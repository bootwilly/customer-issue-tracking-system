<?php

use App\Models\ClientTicket;
use App\Models\Issue;
use App\Models\Project;
use App\Models\User;
use App\Services\IssueService;
use App\Services\TicketService;
use Spatie\Permission\PermissionRegistrar;

function pmMakeUser(string $account, string $type, array $roles, Project $p): User
{
    $u = User::create(['account' => $account, 'name' => $account, 'email' => "$account@pm.test", 'password' => '123456', 'user_type' => $type, 'status' => 'active']);
    $p->members()->attach($u->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($p->id);
    $u->assignRole($roles);
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);

    return $u->fresh();
}

beforeEach(function () {
    $this->p = Project::create(['code' => 'PMX', 'name' => 'PMX', 'ticket_prefix' => 'PMX']);
    $this->client = pmMakeUser('pmcli', 'client', ['client_pm'], $this->p);
    $this->pm = pmMakeUser('pmpm', 'internal', ['internal_pm'], $this->p);
    $this->dev = pmMakeUser('pmdev', 'internal', ['dev'], $this->p);
    $this->sess = ['project_id' => $this->p->id];
    $this->pmTicket = fn (string $title = '按鈕錯位') => app(TicketService::class)->create($this->client, $this->p->id, [
        'ticket_type' => 'anomaly', 'anomaly_category' => 'ui', 'title' => $title, 'content_html' => '<p>內容</p>',
    ]);
});

it('renders PM index with both areas', function () {
    $t = ($this->pmTicket)('待接收單');
    $i = app(IssueService::class)->createInternal($this->pm, $this->p->id, 'pm', ['title' => '內部單A', 'content_html' => '<p>x</p>', 'severity' => 4]);
    $this->withSession($this->sess)->actingAs($this->pm)->get('/pm')
        ->assertOk()->assertSee('客戶問題')->assertSee('內部問題清單')
        ->assertSee($t->ticket_no)->assertSee('待接收')->assertSee('瀏覽')
        ->assertSee($i->issue_no)->assertSee('內部')->assertSee('新增內部問題');
});

it('accepts a ticket and creates an issue from it', function () {
    $t = ($this->pmTicket)();
    $this->withSession($this->sess)->actingAs($this->pm)->get("/pm/tickets/{$t->id}")->assertOk()->assertSee('接收客戶單');
    $this->withSession($this->sess)->actingAs($this->pm)->post("/pm/tickets/{$t->id}/accept")->assertSessionHas('ok');
    expect($t->fresh()->accepted_at)->not->toBeNull();

    $this->withSession($this->sess)->actingAs($this->pm)->get("/pm/tickets/{$t->id}")->assertOk()->assertSee('建立內部單');
    $res = $this->withSession($this->sess)->actingAs($this->pm)->post("/pm/tickets/{$t->id}/issue", [
        'title' => '修正按鈕', 'severity' => 3, 'process_type' => 'ui_change', 'content_html' => '<p>請修正</p>',
    ]);
    $i = Issue::first();
    $res->assertRedirect("/issues/{$i->id}");
    expect($i->origin)->toBe('client')->and($i->tickets()->count())->toBe(1)->and($t->fresh()->needs_pm_action)->toBeFalse();
    $this->withSession($this->sess)->actingAs($this->pm)->get("/issues/{$i->id}")->assertOk()->assertSee($i->issue_no)->assertSee($t->ticket_no);
});

it('creates an internal pm issue', function () {
    $this->withSession($this->sess)->actingAs($this->pm)->get('/pm/issues/create')->assertOk();
    $this->withSession($this->sess)->actingAs($this->pm)->post('/pm/issues', ['title' => '內部', 'severity' => 2, 'content_html' => '<p>x</p>'])->assertRedirect();
    expect(Issue::first()->origin)->toBe('pm');
});

it('dispatching to dev from the issue page sets ticket dispatched_at', function () {
    $t = ($this->pmTicket)();
    app(TicketService::class)->accept($this->pm, $t->id, $this->p->id);
    $i = app(IssueService::class)->createFromTickets($this->pm, $this->p->id, [$t->id], ['title' => 'x', 'content_html' => '<p>x</p>', 'severity' => 3]);
    $this->withSession($this->sess)->actingAs($this->pm)->get("/issues/{$i->id}")->assertOk()->assertSee('指派 DEV')->assertSee('pmdev');
    $this->withSession($this->sess)->actingAs($this->pm)->post("/issues/{$i->id}/transition", ['to' => 'dev_todo', 'assignee_dev_id' => $this->dev->id])->assertSessionHas('ok');
    expect($i->fresh()->status)->toBe('dev_todo')->and($t->fresh()->dispatched_at)->not->toBeNull();
    $this->withSession($this->sess)->actingAs($this->dev)->get("/issues/{$i->id}")->assertOk();
});

it('forbids client users on /pm and /issues', function () {
    $i = app(IssueService::class)->createInternal($this->pm, $this->p->id, 'pm', ['title' => 'x', 'content_html' => '<p>x</p>']);
    $this->withSession($this->sess)->actingAs($this->client)->get('/pm')->assertForbidden();
    $this->withSession($this->sess)->actingAs($this->client)->get("/issues/{$i->id}")->assertForbidden();
});

it('runs the due change approve flow', function () {
    $is = app(IssueService::class);
    $t = ($this->pmTicket)();
    app(TicketService::class)->accept($this->pm, $t->id, $this->p->id);
    $i = $is->createFromTickets($this->pm, $this->p->id, [$t->id], ['title' => 'x', 'content_html' => '<p>x</p>', 'severity' => 3]);
    $is->transition($this->pm, $i->id, $this->p->id, 'dev_todo', ['assignee_dev_id' => $this->dev->id]);
    $is->transition($this->dev, $i->id, $this->p->id, 'dev_doing', ['planned_due' => '2026-12-01']);

    $this->withSession($this->sess)->actingAs($this->dev)->post("/issues/{$i->id}/due-request", ['new_due' => '2026-12-20', 'reason' => '需求變更'])->assertSessionHas('ok');
    $req = $i->dueRequests()->first();
    $this->withSession($this->sess)->actingAs($this->pm)->get('/pm')->assertSee('待審核的延期申請')->assertSee('核准');
    $this->withSession($this->sess)->actingAs($this->pm)->post("/pm/due/{$req->id}/review", ['approve' => 1])->assertSessionHas('ok');
    expect($i->fresh()->current_due->toDateString())->toBe('2026-12-20');
});

it('settles an accepted ticket to pending close', function () {
    $t = ($this->pmTicket)();
    $this->withSession($this->sess)->actingAs($this->pm)->post("/pm/tickets/{$t->id}/accept");
    $this->withSession($this->sess)->actingAs($this->pm)->post("/pm/tickets/{$t->id}/settle", ['action' => 'pending', 'note' => ''])->assertSessionHasErrors('note');
    $this->withSession($this->sess)->actingAs($this->pm)->post("/pm/tickets/{$t->id}/settle", ['action' => 'pending', 'note' => '已確認處理'])->assertSessionHas('ok');
    $t = ClientTicket::find($t->id);
    expect($t->status)->toBe('pending_close')->and($t->needs_pm_action)->toBeFalse();
});
