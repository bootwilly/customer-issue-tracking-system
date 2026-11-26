<?php

use App\Models\Issue;
use App\Models\Meeting;
use App\Models\MeetingComment;
use App\Models\MeetingItem;
use App\Models\Project;
use App\Models\User;
use App\Services\Export\MeetingExportService;
use Spatie\Permission\PermissionRegistrar;

// meeting list filters (type / status / date range) and Excel export (all matching, or one meeting)

function meUser(string $account, string $type, array $roles, Project $p): User
{
    $u = User::create(['account' => $account, 'name' => $account, 'email' => "$account@me.test", 'password' => '123456', 'user_type' => $type, 'status' => 'active']);
    $p->members()->attach($u->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($p->id);
    $u->assignRole($roles);
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);

    return $u->fresh();
}

function meText(array $sections): string
{
    return implode("\n", array_map(fn ($x) => is_string($x) ? $x : $x->text, array_merge(...array_values($sections))));
}

beforeEach(function () {
    $this->withoutVite();
    $this->p = Project::create(['code' => 'ME', 'name' => 'ME', 'ticket_prefix' => 'ME']);
    $this->pm = meUser('mepm', 'internal', ['internal_pm'], $this->p);
    $this->cpm = meUser('mecpm', 'client', ['client_pm'], $this->p);
    $this->padm = meUser('mepadm', 'internal', ['project_admin'], $this->p);
    $this->as = fn (User $u) => $this->actingAs($u)->withSession(['project_id' => $this->p->id]);
    $this->make = function (array $in) {
        ($this->as)($this->pm)->post(route('meetings.store'), $in + ['type' => 'regular', 'mode' => 'scheduled'])->assertRedirect();

        return Meeting::latest('id')->first();
    };
    $this->a = ($this->make)(['title' => '十二月週會', 'start_at' => '2026-12-01 10:00', 'topics' => "入庫異常\n報表速度"]);
    $this->b = ($this->make)(['title' => '需求檢討', 'type' => 'review', 'start_at' => '2026-12-15 09:00']);
    $this->c = ($this->make)(['title' => '一月週會', 'start_at' => '2027-01-05 23:30']); // still 2027-01-05 in Taipei
});

it('filters the list by type, status and Taipei date range', function () {
    $see = fn (array $q) => ($this->as)($this->pm)->get(route('meetings.index', $q + ['tab' => 'all']))->assertOk();
    $see(['type' => 'review'])->assertSee('需求檢討')->assertDontSee('十二月週會')->assertDontSee('一月週會');
    $see(['from' => '2026-12-01', 'to' => '2026-12-31'])->assertSee('十二月週會')->assertSee('需求檢討')->assertDontSee('一月週會');
    $see(['from' => '2027-01-05', 'to' => '2027-01-05'])->assertSee('一月週會')->assertDontSee('需求檢討');
    $see(['to' => '2026-12-01', 'from' => '2026-12-31'])->assertSee('需求檢討')->assertDontSee('一月週會'); // swapped range
    ($this->as)($this->pm)->post(route('meetings.status', $this->b->id), ['to' => 'cancelled']);
    expect($this->b->fresh()->status)->toBe('cancelled');
    $see(['status' => 'cancelled'])->assertSee('需求檢討')->assertDontSee('十二月週會');
    ($this->as)($this->pm)->get(route('meetings.index', ['tab' => 'open', 'status' => 'cancelled']))->assertOk()->assertDontSee('需求檢討'); // not in tab
    $see(['type' => 'bogus', 'status' => 'x', 'from' => '2026-13-40'])->assertSee('一月週會'); // junk ignored
});

it('exports every matching meeting with items and comments, and one meeting alone', function () {
    $issue = Issue::create(['project_id' => $this->p->id, 'issue_no' => 'ME-I-SECRET', 'origin' => 'pm', 'title' => '內部單', 'content_html' => '<p>x</p>', 'stage' => 'PM', 'status' => 'new', 'created_by' => $this->pm->id]);
    MeetingItem::where('meeting_id', $this->a->id)->orderBy('id')->first()
        ->update(['discussion' => "第一行\n第二行", 'conclusion' => '改掃描邏輯', 'owner_id' => $this->pm->id, 'due_date' => '2026-12-10', 'issue_id' => $issue->id]);
    $this->a->update(['notes_html' => '<p>對外紀錄</p>', 'internal_html' => '<p>報價底線</p>']);
    MeetingComment::create(['meeting_id' => $this->a->id, 'user_id' => $this->pm->id, 'body' => '內部留言', 'is_internal' => true]);
    MeetingComment::create(['meeting_id' => $this->a->id, 'user_id' => $this->pm->id, 'body' => '公開留言', 'is_internal' => false]);

    $svc = app(MeetingExportService::class);
    $none = ['type' => null, 'status' => null, 'from' => null, 'to' => null];
    $book = $svc->listBook($this->pm, $this->p->id, 'all', null, ['type' => 'regular'] + $none);
    [$ms, $is] = $book->sheets;
    expect(array_column($ms->rows, 'title'))->toBe(['一月週會', '十二月週會'])
        ->and($ms->rows[1]['item_count'])->toBe(2)->and($ms->rows[1]['attendees'])->toContain('mepm')
        ->and(collect($is->rows)->pluck('topic')->all())->toContain('入庫異常')
        ->and(collect($is->rows)->firstWhere('topic', '入庫異常')['issue']->text)->toBe('ME-I-SECRET');
    expect(meText($ms->details[1]->sections))->toContain('對外紀錄')->toContain('報價底線')->toContain('改掃描邏輯')->toContain('第二行')->toContain('內部留言');
    expect($book->info['類型'])->not->toBe('全部');

    // client: shared meetings only; before publishing no record / details; never internal notes, comments or issue links
    ($this->as)($this->pm)->post(route('meetings.share', $this->a->id), ['visible' => 1]);
    $cb = $svc->listBook($this->cpm, $this->p->id, 'all', null, $none);
    expect(array_column($cb->sheets[0]->rows, 'title'))->toBe(['十二月週會'])
        ->and($cb->sheets[0]->columns)->not->toHaveKey('shared')->and($cb->sheets[1]->columns)->not->toHaveKey('issue');
    expect(meText($cb->sheets[0]->details[0]->sections))->toContain('紀錄尚未發布')->toContain('公開留言')
        ->not->toContain('報價底線')->not->toContain('內部留言')->not->toContain('改掃描邏輯')->not->toContain('ME-I-SECRET');

    // downloads
    ($this->as)($this->pm)->get(route('meetings.export', ['tab' => 'all', 'type' => 'review']))->assertOk()
        ->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    $one = ($this->as)($this->pm)->get(route('meetings.export-one', $this->a->id))->assertOk();
    expect($one->headers->get('content-disposition'))->toContain('ME_');
    expect($svc->oneBook($this->pm, $this->p->id, $this->a->id)->sheets[0]->rows)->toHaveCount(1);
    ($this->as)($this->cpm)->get(route('meetings.export-one', $this->b->id))->assertNotFound(); // not shared
    ($this->as)($this->padm)->get(route('meetings.export'))->assertForbidden(); // no data.export
    ($this->as)($this->pm)->get(route('meetings.index'))->assertSee('匯出 Excel');
    ($this->as)($this->pm)->get(route('meetings.show', $this->a->id))->assertSee(route('meetings.export-one', $this->a->id));
});
