<?php

use App\Models\ActivityEvent;
use App\Models\Attachment;
use App\Models\ClientTicket;
use App\Models\Issue;
use App\Models\IssueComment;
use App\Models\IssueDependency;
use App\Models\IssueImportRow;
use App\Models\Meeting;
use App\Models\Project;
use App\Models\User;
use App\Repositories\ReportRepository;
use App\Repositories\TicketRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\PermissionRegistrar;

// Deleting 新建 internal issues into the project recycle bin; restore / permanent delete by the project admin.

function itUser(string $account, string $type, array $roles, Project $p): User
{
    $u = User::create(['account' => $account, 'name' => $account, 'email' => "$account@t.test", 'password' => '123456', 'user_type' => $type, 'status' => 'active']);
    $p->members()->attach($u->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($p->id);
    $u->assignRole($roles);
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);

    return $u->fresh();
}

beforeEach(function () {
    $this->withoutVite();
    Storage::fake('local');
    $this->p = Project::create(['code' => 'IT', 'name' => 'IT', 'ticket_prefix' => 'IT']);
    $this->pm = itUser('itpm', 'internal', ['internal_pm'], $this->p);
    $this->dev = itUser('itdev', 'internal', ['dev'], $this->p);
    $this->padm = itUser('itadm', 'internal', ['project_admin'], $this->p);
    $this->client = itUser('itcli', 'client', ['client_pm'], $this->p);
    $this->as = fn (User $u) => $this->actingAs($u)->withSession(['project_id' => $this->p->id]);
    $this->issue = fn (string $no, string $status = 'new', ?User $by = null) => Issue::create(['project_id' => $this->p->id, 'issue_no' => $no, 'origin' => 'pm',
        'title' => "標題{$no}", 'content_html' => '<p>x</p>', 'stage' => 'PM', 'status' => $status, 'created_by' => ($by ?? $this->pm)->id]);
    $this->ticket = fn (string $no) => ClientTicket::create(['project_id' => $this->p->id, 'ticket_no' => $no, 'ticket_type' => 'anomaly', 'title' => '客戶標題',
        'status' => 'in_progress', 'content_html' => '<p>c</p>', 'created_by' => $this->client->id, 'current_rev' => 1, 'accepted_at' => now()]);
});

it('moves a new issue to the recycle bin and hides it everywhere', function () {
    $i = ($this->issue)('IT-1');
    $keep = ($this->issue)('IT-2');
    ($this->as)($this->pm)->get(route('issues.show', $i->id))->assertOk()->assertSee('移到回收筒？', false);

    ($this->as)($this->pm)->delete(route('issues.destroy', $i->id))->assertRedirect(route('pm.index'))->assertSessionHas('ok');

    expect(Issue::find($i->id))->toBeNull()
        ->and(Issue::withTrashed()->find($i->id)->deleted_by)->toBe($this->pm->id)
        ->and(ActivityEvent::where(['subject_type' => 'issue', 'subject_id' => $i->id, 'event_type' => 'issue_deleted'])->exists())->toBeTrue();
    ($this->as)($this->pm)->get(route('pm.index'))->assertOk()->assertDontSee('標題IT-1')->assertSee('標題IT-2');
    ($this->as)($this->pm)->get(route('issues.show', $i->id))->assertNotFound()->assertSee('移到回收筒')->assertSee('需要還原請聯絡專案管理員');
    ($this->as)($this->client)->get(route('issues.show', $i->id))->assertForbidden();

    // raw report queries skip it too
    $i->forceFill(['current_due' => '2026-01-01'])->saveQuietly();
    $keep->forceFill(['current_due' => '2026-01-01'])->save();
    $overdue = app(ReportRepository::class)->overdueIssues($this->p->id, ['new'], '2026-06-01');
    expect($overdue->pluck('issue_no')->all())->toBe(['IT-2']);
});

it('only deletes new issues, by their creator or an internal PM', function () {
    $doing = ($this->issue)('IT-1', 'dev_todo');
    ($this->as)($this->pm)->get(route('issues.show', $doing->id))->assertOk()->assertDontSee('移到回收筒？', false);
    ($this->as)($this->pm)->delete(route('issues.destroy', $doing->id))->assertSessionHas('err', '只有「新建」狀態的內部單可以刪除，其它請用取消。');

    $pmIssue = ($this->issue)('IT-2');
    ($this->as)($this->dev)->delete(route('issues.destroy', $pmIssue->id))->assertSessionHas('err');
    expect(Issue::find($pmIssue->id))->not->toBeNull();

    $own = ($this->issue)('IT-3', 'new', $this->dev);
    ($this->as)($this->dev)->delete(route('issues.destroy', $own->id))->assertSessionHas('ok');
    expect(Issue::find($own->id))->toBeNull();

    ($this->as)($this->client)->delete(route('issues.destroy', $pmIssue->id))->assertForbidden();
});

it('bulk deletes the checked new issues and reports the skipped ones', function () {
    $a = ($this->issue)('IT-1');
    $b = ($this->issue)('IT-2');
    $c = ($this->issue)('IT-3', 'dev_todo');
    ($this->as)($this->pm)->get(route('pm.index'))->assertOk()->assertSee('刪除選取');
    ($this->as)($this->client)->post(route('issues.bulk-destroy'), ['issue_ids' => [$a->id]])->assertForbidden();

    ($this->as)($this->pm)->post(route('issues.bulk-destroy'), ['issue_ids' => [$a->id, $b->id, $c->id, 999999]])
        ->assertSessionHas('ok', '已將 2 張移到回收筒：IT-1、IT-2。')
        ->assertSessionHas('err', fn ($m) => str_contains($m, 'IT-3') && str_contains($m, '1 張 找不到'));
    expect(Issue::whereIn('id', [$a->id, $b->id, $c->id])->pluck('issue_no')->all())->toBe(['IT-3']);
});

it('tells PM to act on a client ticket whose only issue was deleted', function () {
    $t = ($this->ticket)('IT-0001');
    $i = ($this->issue)('IT-1');
    DB::table('ticket_issue_links')->insert(['ticket_id' => $t->id, 'issue_id' => $i->id, 'based_on_rev' => 1, 'linked_by' => $this->pm->id, 'linked_at' => now()]);

    ($this->as)($this->pm)->delete(route('issues.destroy', $i->id))->assertSessionHas('ok');

    expect($t->fresh()->needs_pm_action)->toBeTrue()
        ->and(app(TicketRepository::class)->linkedIssueStates([$t->id]))->toHaveCount(0)
        ->and(ActivityEvent::where(['subject_type' => 'ticket', 'subject_id' => $t->id])->where('event_type', 'like', 'issue_%')->exists())->toBeFalse(); // clients read ticket events
});

it('lets the project admin restore a deleted issue', function () {
    $i = ($this->issue)('IT-1');
    ($this->as)($this->pm)->delete(route('issues.destroy', $i->id));
    ($this->as)($this->pm)->get(route('padmin.trash'))->assertForbidden();
    ($this->as)($this->padm)->get(route('padmin.trash'))->assertOk()->assertSee('IT-1')->assertSee('itpm');

    ($this->as)($this->padm)->post(route('padmin.trash.issues.restore', $i->id))->assertSessionHas('ok', '已還原內部單。');

    expect(Issue::find($i->id)?->deleted_by)->toBeNull()
        ->and(ActivityEvent::where(['subject_id' => $i->id, 'event_type' => 'issue_restored'])->exists())->toBeTrue();
    ($this->as)($this->pm)->get(route('issues.show', $i->id))->assertOk();
});

it('deletes for good only with the issue number, removing every row that points at the issue', function () {
    $i = ($this->issue)('IT-1');
    $up = ($this->issue)('IT-2');
    $t = ($this->ticket)('IT-0001');
    DB::table('ticket_issue_links')->insert(['ticket_id' => $t->id, 'issue_id' => $i->id, 'based_on_rev' => 1, 'linked_by' => $this->pm->id, 'linked_at' => now()]);
    $c = IssueComment::create(['issue_id' => $i->id, 'content_html' => '<p>c</p>', 'created_by' => $this->pm->id, 'created_at' => now()]);
    DB::table('issue_addenda')->insert(['issue_id' => $i->id, 'content_html' => '<p>a</p>', 'source_comment_id' => $c->id, 'created_by' => $this->pm->id, 'created_at' => now()]);
    DB::table('issue_watchers')->insert(['issue_id' => $i->id, 'user_id' => $this->dev->id, 'created_at' => now()]);
    DB::table('issue_tags')->insert(['issue_id' => $i->id, 'tag_code' => 'x', 'created_by' => $this->pm->id, 'created_at' => now()]);
    IssueDependency::create(['issue_id' => $up->id, 'depends_on_id' => $i->id, 'kind' => 'blocks', 'created_by' => $this->pm->id]);
    IssueImportRow::create(['project_id' => $this->p->id, 'source' => 'xls', 'source_no' => '1', 'issue_id' => $i->id, 'content_hash' => str_repeat('a', 64), 'created_by' => $this->pm->id]);
    Storage::disk('local')->put('attachments/2026/12/only.png', 'x');
    Storage::disk('local')->put('attachments/2026/12/shared.png', 'y');
    $att = fn (string $type, int $owner, string $path) => Attachment::create(['owner_type' => $type, 'owner_id' => $owner, 'state' => 'bound', 'disk_path' => $path,
        'original_name' => 'a.png', 'mime' => 'image/png', 'size' => 1, 'sha256' => hash('sha256', $path), 'uploaded_by' => $this->pm->id]);
    $att('issue', $i->id, 'attachments/2026/12/only.png');
    $att('issue_comment', $c->id, 'attachments/2026/12/shared.png');
    $att('issue', $up->id, 'attachments/2026/12/shared.png');
    ($this->as)($this->pm)->post(route('meetings.store'), ['type' => 'regular', 'title' => '週會', 'start_at' => '2026-12-01 10:00', 'mode' => 'scheduled']);
    $m = Meeting::latest('id')->first();
    ($this->as)($this->pm)->postJson(route('meetings.items.issues', $m->id), ['issue_ids' => [$i->id]])->assertJsonPath('added', 1);

    ($this->as)($this->pm)->delete(route('issues.destroy', $i->id));
    ($this->as)($this->padm)->delete(route('padmin.trash.issues.purge', $i->id), ['confirm_no' => 'IT-9'])->assertSessionHas('err', '輸入的單號不符，未刪除。');
    expect(Issue::withTrashed()->find($i->id))->not->toBeNull();

    ($this->as)($this->padm)->delete(route('padmin.trash.issues.purge', $i->id), ['confirm_no' => 'it-1'])->assertSessionHas('ok', '已永久刪除。');

    expect(Issue::withTrashed()->find($i->id))->toBeNull();
    foreach (['ticket_issue_links', 'issue_comments', 'issue_addenda', 'issue_watchers', 'issue_tags', 'issue_import_rows'] as $table) {
        expect(DB::table($table)->where('issue_id', $i->id)->count())->toBe(0, $table);
    }
    expect(IssueDependency::count())->toBe(0)
        ->and(Attachment::whereIn('owner_type', ['issue', 'issue_comment'])->pluck('owner_id')->all())->toBe([$up->id])
        ->and(Storage::disk('local')->exists('attachments/2026/12/only.png'))->toBeFalse()
        ->and(Storage::disk('local')->exists('attachments/2026/12/shared.png'))->toBeTrue();
    $item = $m->items()->first();
    expect($item->issue_id)->toBeNull()->and($item->discussion)->toStartWith('（原關聯內部單 IT-1 已永久刪除）');
    ($this->as)($this->pm)->get(route('issues.show', $i->id))->assertNotFound()->assertSee('永久刪除，無法復原');
});

it('keeps a meeting item linked to an issue in the recycle bin when the item is edited', function () {
    $i = ($this->issue)('IT-1');
    ($this->as)($this->pm)->post(route('meetings.store'), ['type' => 'regular', 'title' => '週會', 'start_at' => '2026-12-01 10:00', 'mode' => 'scheduled']);
    $m = Meeting::latest('id')->first();
    ($this->as)($this->pm)->postJson(route('meetings.items.issues', $m->id), ['issue_ids' => [$i->id]])->assertJsonPath('added', 1);
    $item = $m->items()->first();
    ($this->as)($this->pm)->delete(route('issues.destroy', $i->id))->assertSessionHas('ok');

    ($this->as)($this->pm)->get(route('meetings.show', $m->id))->assertOk()->assertSee('原關聯的內部單在回收筒');
    // the page sends the (empty) issue number box with every autosave
    ($this->as)($this->pm)->putJson(route('meetings.items.update', [$m->id, $item->id]), ['issue_no' => '', 'discussion' => '改內容'])
        ->assertOk()->assertJsonPath('item.issue_trashed', true)->assertJsonPath('item.issue_url', null);
    expect($item->fresh()->issue_id)->toBe($i->id)->and($item->fresh()->discussion)->toBe('改內容');
    ($this->as)($this->pm)->get(route('meetings.items.issue', [$m->id, $item->id]))
        ->assertSessionHas('err', '這個討論事項連結的內部單在回收筒，請專案管理員還原或永久刪除後再轉。');

    ($this->as)($this->padm)->post(route('padmin.trash.issues.restore', $i->id))->assertSessionHas('ok');
    ($this->as)($this->pm)->putJson(route('meetings.items.update', [$m->id, $item->id]), ['discussion' => '還原後'])
        ->assertOk()->assertJsonPath('item.issue_no', 'IT-1')->assertJsonPath('item.issue_trashed', false);

    // typing another number still replaces the link on purpose
    ($this->as)($this->pm)->delete(route('issues.destroy', $i->id));
    ($this->issue)('IT-2');
    ($this->as)($this->pm)->putJson(route('meetings.items.update', [$m->id, $item->id]), ['issue_no' => 'IT-2'])->assertOk()->assertJsonPath('item.issue_no', 'IT-2');
});

it('lists recycle bin actions in the project change log', function () {
    $i = ($this->issue)('IT-1');
    ($this->issue)('IT-2');
    ($this->as)($this->pm)->delete(route('issues.destroy', $i->id));
    ($this->as)($this->padm)->post(route('padmin.trash.issues.restore', $i->id));
    ($this->as)($this->pm)->post(route('issues.comment', $i->id), ['content_html' => '<p>一般留言</p>']);

    ($this->as)($this->padm)->get(route('padmin.events'))->assertOk()
        ->assertSee('刪除內部單（回收筒）')->assertSee('還原內部單')->assertSee('IT-1')->assertSee('標題IT-1')
        ->assertDontSee('內部留言');
});
