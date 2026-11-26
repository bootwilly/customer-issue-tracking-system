<?php

use App\Models\ActivityEvent;
use App\Models\Issue;
use App\Models\MesPage;
use App\Models\Project;
use App\Models\User;
use App\Services\IssueService;
use App\Services\WorkflowService;
use Spatie\Permission\PermissionRegistrar;

function dtMakeUser(string $account, string $type, array $roles, Project $p): User
{
    $u = User::create(['account' => $account, 'name' => $account, 'email' => "$account@t.test", 'password' => '123456', 'user_type' => $type, 'status' => 'active']);
    $p->members()->attach($u->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($p->id);
    $u->assignRole($roles);
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);

    return $u->fresh();
}

function dtIssue(User $by, Project $p, array $over = []): Issue
{
    $i = app(IssueService::class)->createInternal($by, $p->id, 'dev', ['title' => $over['title'] ?? '開發項目', 'content_html' => '<p>x</p>', 'severity' => 2]);
    unset($over['title']);
    if ($over) {
        $i->forceFill($over)->save();
    }

    return $i->fresh();
}

beforeEach(function () {
    $this->p = Project::create(['code' => 'MES', 'name' => 'MES', 'ticket_prefix' => 'MES']);
    $this->pm = dtMakeUser('dpm', 'internal', ['internal_pm'], $this->p);
    $this->dev = dtMakeUser('ddev', 'internal', ['dev'], $this->p);
    $this->dev2 = dtMakeUser('ddev2', 'internal', ['dev'], $this->p);
    $this->tester = dtMakeUser('dtest', 'internal', ['test'], $this->p);
    $this->client = dtMakeUser('dclient', 'client', ['client_pm'], $this->p);
    $this->as = fn (User $u) => $this->actingAs($u)->withSession(['project_id' => $this->p->id]);
});

it('filters the dev list by owner, status, keyword and overdue', function () {
    $mine = dtIssue($this->dev, $this->p, ['title' => '我的單', 'assignee_dev_id' => $this->dev->id, 'current_due' => '2020-01-01']);
    $other = dtIssue($this->dev2, $this->p, ['title' => '別人的單', 'assignee_dev_id' => $this->dev2->id]);
    $future = dtIssue($this->dev, $this->p, ['title' => '未來的單', 'assignee_dev_id' => $this->dev->id, 'current_due' => '2099-01-01']);

    // default for dev users: mine only
    ($this->as)($this->dev)->get('/dev')->assertOk()->assertSee('我的單')->assertSee('未來的單')->assertDontSee('別人的單');
    ($this->as)($this->dev)->get('/dev?f=1')->assertSee('別人的單');
    ($this->as)($this->dev)->get('/dev?f=1&overdue=1')->assertSee('我的單')->assertDontSee('未來的單')->assertDontSee('別人的單');
    ($this->as)($this->dev)->get('/dev?f=1&q=未來')->assertSee('未來的單')->assertDontSee('我的單');
    ($this->as)($this->dev)->get('/dev?f=1&tab=all&status=dev_todo')->assertSee('別人的單');
});

it('groups the schedule list by week', function () {
    $tz = 'Asia/Taipei';
    dtIssue($this->dev, $this->p, ['title' => '逾期單', 'assignee_dev_id' => $this->dev->id, 'current_due' => now($tz)->subDays(10)->toDateString()]);
    dtIssue($this->dev, $this->p, ['title' => '本週單', 'assignee_dev_id' => $this->dev->id, 'current_due' => now($tz)->endOfWeek()->toDateString()]);
    dtIssue($this->dev, $this->p, ['title' => '下週單', 'assignee_dev_id' => $this->dev->id, 'current_due' => now($tz)->addWeek()->endOfWeek()->toDateString()]);
    dtIssue($this->dev, $this->p, ['title' => '之後單', 'assignee_dev_id' => $this->dev->id, 'current_due' => now($tz)->addWeeks(6)->toDateString()]);
    dtIssue($this->dev, $this->p, ['title' => '無日期單', 'assignee_dev_id' => $this->dev->id]);

    ($this->as)($this->dev)->get('/dev?tab=schedule&f=1&mine=1')->assertOk()
        ->assertSeeInOrder(['已逾期', '逾期單', '本週', '本週單', '下週', '下週單', '之後', '之後單'])
        ->assertDontSee('無日期單');
});

it('lets dev create a dev issue and rejects clients', function () {
    ($this->as)($this->dev)->post('/dev/issues', ['title' => '新功能', 'content_html' => '<p>內容</p>', 'severity' => 3])->assertRedirect();
    $i = Issue::where('title', '新功能')->first();
    expect($i->origin)->toBe('dev')->and($i->status)->toBe('dev_todo');
    ($this->as)($this->dev)->post('/dev/packages', ['name' => 'v9'])->assertRedirect();
    ($this->as)($this->dev)->get('/dev')->assertSee('v9');
});

it('lets test create a newly found issue with creator-only severity', function () {
    ($this->as)($this->tester)->get('/test/issues/create')->assertOk()->assertSee('ddev');
    ($this->as)($this->tester)->post('/test/issues', [
        'title' => '上線前發現', 'severity' => 4, 'assignee_dev_id' => $this->dev->id, 'content_html' => '<p>壞了</p>',
    ])->assertRedirect();
    $i = Issue::where('title', '上線前發現')->first();
    expect($i->origin)->toBe('test')->and($i->status)->toBe('test_found')->and($i->assignee_dev_id)->toBe($this->dev->id);

    $wf = app(WorkflowService::class);
    expect($wf->canEditField($this->tester, 'severity', $i->origin, $i->stage, $i->created_by, $this->p->id))->toBeTrue()
        ->and($wf->canEditField(dtMakeUser('dtest2', 'internal', ['test'], $this->p), 'severity', $i->origin, $i->stage, $i->created_by, $this->p->id))->toBeFalse();

    ($this->as)($this->tester)->get('/test?tab=bounced')->assertSee('上線前發現')->assertSee('新發現');
    ($this->as)($this->tester)->get('/test')->assertDontSee('上線前發現');
    // dev cannot create test issues
    ($this->as)($this->dev)->get('/test/issues/create')->assertForbidden();
});

it('sorts the timeline and shows the overdue chip', function () {
    dtIssue($this->dev, $this->p, ['title' => '先到期', 'assignee_dev_id' => $this->dev->id, 'current_due' => '2020-01-01']);
    dtIssue($this->dev, $this->p, ['title' => '後到期', 'assignee_dev_id' => $this->dev->id, 'current_due' => '2099-01-01']);
    $c = ($this->as)($this->dev)->get('/timeline?sort=due')->assertOk();
    $c->assertSeeInOrder(['已逾期', '先到期', '之後', '後到期'])->assertSee('已過預計完工 1');
    // role defaults: dev -> due, pm -> created
    ($this->as)($this->dev)->get('/timeline')->assertSee('已逾期');
    ($this->as)($this->pm)->get('/timeline')->assertOk()->assertSee('今天')->assertSee('待接收 0');
    ($this->as)($this->pm)->get('/timeline?sort=bogus')->assertOk();
});

it('registers MES pages with validation, import and deactivate', function () {
    $s = fn () => ($this->as)($this->pm);
    $s()->post('/mes', ['node_type' => 'folder', 'name' => '生產'])->assertRedirect();
    $folder = MesPage::where('name', '生產')->first();
    $s()->post('/mes', ['node_type' => 'page', 'name' => '工單', 'page_path' => '~/Production/WorkOrder.aspx', 'parent_id' => $folder->id])->assertRedirect();
    $page = MesPage::where('name', '工單')->first();
    expect($page->page_file)->toBe('WorkOrder.aspx')->and($page->parent_id)->toBe($folder->id);

    $s()->post('/mes', ['node_type' => 'page', 'name' => '壞', 'page_path' => '/x/y.html'])->assertSessionHas('err');
    $s()->post('/mes', ['node_type' => 'page', 'name' => '重複', 'page_path' => '~/Production/WorkOrder.aspx'])->assertSessionHas('err');
    expect(MesPage::count())->toBe(2);

    $paths = "~/Production/WorkOrder.aspx\n~/Quality/Inspect/Check.aspx\nbad.txt\n~/Quality/Report.aspx";
    $s()->post('/mes/import/preview', ['paths' => $paths])->assertOk()->assertSee('已存在')->assertSee('新增')->assertSee('無效')->assertSee('Inspect');
    expect(MesPage::count())->toBe(2);
    $s()->post('/mes/import/apply', ['paths' => $paths])->assertRedirect('/mes');
    expect(MesPage::where('node_type', 'page')->count())->toBe(3)
        ->and(MesPage::where('name', 'Quality')->count())->toBe(1)
        ->and(MesPage::where('name', 'Check')->first()->parent->name)->toBe('Inspect');
    expect(ActivityEvent::where('subject_type', 'mes')->count())->toBeGreaterThan(3);

    $s()->post("/mes/{$page->id}/toggle")->assertRedirect('/mes');
    expect($page->fresh()->is_active)->toBeFalse();
    $s()->put("/mes/{$page->id}", ['name' => '工單改', 'page_path' => '~/Production/WO2.aspx', 'sort' => 5])->assertRedirect('/mes');
    expect($page->fresh()->page_file)->toBe('WO2.aspx');
    $s()->get('/mes')->assertOk()->assertSee('Web.sitemap')->assertDontSee('（下一版）');
});

it('forbids clients from all four pages', function () {
    foreach (['/dev', '/test', '/timeline', '/mes', '/dev/issues/create', '/test/issues/create'] as $url) {
        ($this->as)($this->client)->get($url)->assertForbidden();
    }
    ($this->as)($this->client)->post('/mes', ['name' => 'x'])->assertForbidden();
});

it('lets every internal role browse every internal page while actions stay gated', function () {
    $t = app(\App\Services\TicketService::class)->create($this->client, $this->p->id, ['ticket_type' => 'anomaly', 'title' => '客戶單X', 'content_html' => '<p>x</p>']);
    foreach ([$this->dev, $this->tester, $this->pm] as $u) {
        foreach (['/pm', "/pm/tickets/{$t->id}", '/dev', '/test', '/timeline', '/mes'] as $url) {
            ($this->as)($u)->get($url)->assertOk();
        }
        ($this->as)($u)->get('/pm')->assertSee('問題管理')->assertSee('開發管理')->assertSee('測試管理')->assertSee('MES 頁面');
    }
    // dev: read-only on PM pages, MES manage still allowed by role seed (mes.manage)
    ($this->as)($this->dev)->get("/pm/tickets/{$t->id}")->assertDontSee('接收此單')->assertDontSee(route('pm.tickets.accept', $t->id));
    ($this->as)($this->dev)->get('/pm')->assertDontSee('新增內部問題');
    ($this->as)($this->dev)->post("/pm/tickets/{$t->id}/accept")->assertForbidden();
    ($this->as)($this->dev)->get('/test')->assertDontSee('新增測試異常');
    ($this->as)($this->tester)->get('/dev')->assertDontSee('新增開發問題');
    ($this->as)($this->pm)->get("/pm/tickets/{$t->id}")->assertSee(route('pm.tickets.accept', $t->id));
});
