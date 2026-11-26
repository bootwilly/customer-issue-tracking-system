<?php

use App\Models\ActivityEvent;
use App\Models\ClientTicket;
use App\Models\Issue;
use App\Models\Project;
use App\Models\User;
use App\Services\IssueService;
use App\Services\Report\ReportService;
use App\Services\TicketService;
use Spatie\Permission\PermissionRegistrar;

function rpUser(string $account, string $type, array $roles, Project $p): User
{
    $u = User::create(['account' => $account, 'name' => $account, 'email' => "$account@t.test", 'password' => '123456', 'user_type' => $type, 'status' => 'active']);
    $p->members()->attach($u->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($p->id);
    $u->assignRole($roles);
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);

    return $u->fresh();
}

function rpTicket(Project $p, User $client, string $title): ClientTicket
{
    return app(TicketService::class)->create($client, $p->id, [
        'ticket_type' => 'anomaly', 'anomaly_category' => 'ui', 'title' => $title, 'content_html' => '<p>x</p>',
    ]);
}

function rpIssue(Project $p, User $by, array $over = []): Issue
{
    $i = app(IssueService::class)->createInternal($by, $p->id, $over['origin'] ?? 'dev', ['title' => $over['title'] ?? '問題', 'content_html' => '<p>x</p>', 'severity' => 2]);
    unset($over['title'], $over['origin']);
    if ($over) {
        $i->forceFill($over)->save();
    }

    return $i->fresh();
}

beforeEach(function () {
    $this->p = Project::create(['code' => 'MES', 'name' => 'MES', 'ticket_prefix' => 'MES']);
    $this->other = Project::create(['code' => 'OTH', 'name' => 'OTH', 'ticket_prefix' => 'OTH']);
    $this->pm = rpUser('rpm', 'internal', ['internal_pm'], $this->p);
    $this->dev = rpUser('rdev', 'internal', ['dev'], $this->p);
    $this->dev2 = rpUser('rdev2', 'internal', ['dev'], $this->p);
    $this->client = rpUser('rclient', 'client', ['client_pm'], $this->p);
    $this->oclient = rpUser('roclient', 'client', ['client_pm'], $this->other);
    $this->opm = rpUser('ropm', 'internal', ['internal_pm'], $this->other);
    $this->as = fn (User $u) => $this->actingAs($u)->withSession(['project_id' => $this->p->id]);
});

it('forbids clients and allows internal users', function () {
    ($this->as)($this->client)->get(route('reports.index'))->assertForbidden();
    ($this->as)($this->client)->get(route('reports.export'))->assertForbidden();
    ($this->as)($this->pm)->get(route('reports.index'))->assertOk()->assertSee('客戶問題單');
});

it('counts tickets by workflow status and averages', function () {
    $a = rpTicket($this->p, $this->client, 'A');
    $b = rpTicket($this->p, $this->client, 'B');
    rpTicket($this->p, $this->client, 'C');
    rpTicket($this->other, $this->oclient, '別專案');
    app(TicketService::class)->accept($this->pm, $a->id, $this->p->id);
    $a->forceFill(['created_at' => now()->subDays(10), 'accepted_at' => now()->subDays(8)])->save();
    ClientTicket::query()->update(['needs_pm_action' => false]);
    $b->forceFill(['status' => 'closed', 'created_at' => now()->subDays(6), 'closed_at' => now()->subDays(2), 'needs_pm_action' => true])->save();

    $d = app(ReportService::class)->build($this->p->id, null, null);
    $by = collect($d['tickets']['byStatus'])->pluck('count', 'code');
    expect($by['submitted'])->toBe(1)->and($by['in_progress'])->toBe(1)->and($by['closed'])->toBe(1)
        ->and($d['tickets']['opened'])->toBe(3)->and($d['tickets']['closed'])->toBe(1)
        ->and($d['tickets']['avgAccept'])->toBe(2.0)->and($d['tickets']['avgClose'])->toBe(4.0)
        ->and($d['tickets']['needsAction'])->toBe(1);

    ($this->as)($this->pm)->get(route('reports.index'))->assertSee('已結案')->assertSee('處理中');
});

it('groups overdue issues by dev and lists rework top', function () {
    $y = now('Asia/Taipei')->subDays(3)->toDateString();
    rpIssue($this->p, $this->dev, ['title' => '逾期甲', 'assignee_dev_id' => $this->dev->id, 'current_due' => $y, 'rework_count' => 3]);
    rpIssue($this->p, $this->dev, ['title' => '逾期乙', 'assignee_dev_id' => $this->dev->id, 'current_due' => $y, 'rework_count' => 1]);
    rpIssue($this->p, $this->dev2, ['title' => '逾期丙', 'assignee_dev_id' => $this->dev2->id, 'current_due' => $y]);
    rpIssue($this->p, $this->dev, ['title' => '未逾期', 'assignee_dev_id' => $this->dev->id, 'current_due' => now('Asia/Taipei')->addDays(5)->toDateString()]);
    rpIssue($this->p, $this->dev, ['title' => '已上線舊單', 'assignee_dev_id' => $this->dev->id, 'current_due' => $y, 'status' => 'released']);
    rpIssue($this->other, $this->opm, ['origin' => 'pm', 'title' => '他專案逾期', 'current_due' => $y, 'rework_count' => 9]);

    $d = app(ReportService::class)->build($this->p->id, null, null);
    $groups = collect($d['overdue'])->pluck('count', 'name');
    expect($groups->all())->toBe(['rdev' => 2, 'rdev2' => 1])
        ->and($d['rework']['count'])->toBe(2)->and($d['rework']['avg'])->toBe(2.0)
        ->and($d['rework']['top'][0]['rework'])->toBe(3)
        ->and($d['issues']['total'])->toBe(5);

    ($this->as)($this->pm)->get(route('reports.index'))
        ->assertSee('逾期甲')->assertDontSee('他專案逾期')->assertDontSee('未逾期')
        ->assertSee(url('/issues/'.Issue::where('title', '逾期甲')->value('id')));
});

it('builds a 6 month trend with release events', function () {
    $i = rpIssue($this->p, $this->dev, ['title' => '上線單']);
    ActivityEvent::query()->insert([
        'project_id' => $this->p->id, 'subject_type' => 'issue', 'subject_id' => $i->id, 'event_type' => 'status_changed',
        'from_status' => 'release_pending', 'to_status' => 'released', 'actor_id' => $this->pm->id, 'created_at' => now(),
    ]);
    $old = rpTicket($this->p, $this->client, '舊單');
    $old->forceFill(['created_at' => now()->subMonths(2)])->save();

    $d = app(ReportService::class)->build($this->p->id, null, null);
    $cur = now('Asia/Taipei')->format('Y-m');
    $trend = collect($d['trend'])->keyBy('month');
    expect($d['trend'])->toHaveCount(6)->and($trend[$cur]['issues_released'])->toBe(1)->and($trend[$cur]['issues_created'])->toBe(1)
        ->and($trend[now('Asia/Taipei')->subMonthsNoOverflow(2)->format('Y-m')]['tickets_opened'])->toBe(1);
});

it('exports csv with BOM and Chinese labels', function () {
    rpTicket($this->p, $this->client, '=危險標題');
    $res = ($this->as)($this->pm)->get(route('reports.export'));
    $res->assertOk();
    expect($res->headers->get('content-type'))->toContain('text/csv')
        ->and($res->headers->get('content-disposition'))->toContain('.csv');
    $body = $res->streamedContent();
    expect(str_starts_with($body, "\xEF\xBB\xBF"))->toBeTrue()
        ->and($body)->toContain('客戶問題單：狀態分布')->toContain('已提交')->toContain('月趨勢');
});
