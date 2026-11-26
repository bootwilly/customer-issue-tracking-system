<?php

use App\Mcp\Servers\CitsServer;
use App\Mcp\Tools\GetIssueTool;
use App\Mcp\Tools\ListIssuesTool;
use App\Mcp\Tools\ListProjectsTool;
use App\Mcp\Tools\ListTicketsTool;
use App\Models\ActivityEvent;
use App\Models\ApiToken;
use App\Models\ClientTicket;
use App\Models\Issue;
use App\Models\Project;
use App\Models\User;
use App\Services\ApiTokenService;
use App\Services\WorkflowService;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

function mcUser(string $account, string $type, array $roles, array $projects, bool $admin = false): User
{
    $u = User::create(['account' => $account, 'name' => "N-$account", 'email' => "$account@mc.test", 'password' => '123456', 'user_type' => $type, 'status' => 'active', 'is_admin' => $admin]);
    foreach ($projects as $p) {
        $p->members()->attach($u->id);
        app(PermissionRegistrar::class)->setPermissionsTeamId($p->id);
        $u->assignRole($roles);
    }
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);

    return $u->fresh();
}

function mcIssue(Project $p, User $by, string $no, string $title, string $status = 'pending', array $extra = []): Issue
{
    return Issue::create($extra + [
        'project_id' => $p->id, 'issue_no' => $no, 'origin' => 'pm', 'title' => $title,
        'content_html' => '<p>第一行</p><p>第二行 &amp; more</p><script>x</script>', 'stage' => 'PM', 'status' => $status, 'created_by' => $by->id,
    ]);
}

function mcTicket(Project $p, User $by, string $no, string $title, string $status = 'submitted'): ClientTicket
{
    return ClientTicket::create([
        'project_id' => $p->id, 'ticket_no' => $no, 'ticket_type' => 'anomaly', 'title' => $title,
        'content_html' => '<p>x</p>', 'status' => $status, 'created_by' => $by->id,
    ]);
}

/** Bearer JSON-RPC call against the HTTP endpoint. */
function mcRpc($t, ?string $token, string $method, array $params = [])
{
    $headers = ['Accept' => 'application/json, text/event-stream'];
    if ($token !== null) {
        $headers['Authorization'] = "Bearer $token";
    }

    return $t->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => $params], $headers);
}

beforeEach(function () {
    $this->pa = Project::create(['code' => 'MCA', 'name' => 'Alpha', 'ticket_prefix' => 'MCA']);
    $this->pb = Project::create(['code' => 'MCB', 'name' => 'Beta', 'ticket_prefix' => 'MCB']);
    $this->pm = mcUser('mcpm', 'internal', ['internal_pm'], [$this->pa]);
    $this->other = mcUser('mcother', 'internal', ['internal_pm'], [$this->pb]);
    $this->client = mcUser('mccli', 'client', ['client_pm'], [$this->pa]);
    $this->admin = mcUser('mcadmin', 'internal', [], [], true);
    $this->svc = app(ApiTokenService::class);
});

it('creates a token shown once and stores only its hash', function () {
    $res = $this->actingAs($this->pm)->post(route('account.tokens.store'), ['name' => 'laptop', 'expiry' => '30']);
    $res->assertRedirect(route('account.tokens'));
    $plain = session('plain_token');
    expect($plain)->toStartWith('cits_')->and(strlen($plain))->toBe(45);

    $row = ApiToken::first();
    expect($row->token_hash)->toBe(hash('sha256', $plain))->and($row->expires_at)->not->toBeNull();
    expect(json_encode(DB::table('api_tokens')->get()))->not->toContain($plain);

    $page = $this->actingAs($this->pm)->withSession(['plain_token' => $plain])->get(route('account.tokens'));
    $page->assertOk()->assertSee($plain)->assertSee(url('/mcp'));
    // Flash is gone on the next load: plain token is not retrievable again.
    $this->actingAs($this->pm)->get(route('account.tokens'))->assertOk()->assertDontSee($plain);
});

it('revokes only own tokens and never deletes', function () {
    $mine = $this->svc->create($this->pm, 'a', 'none')['token'];
    $theirs = $this->svc->create($this->other, 'b', 'none')['token'];

    $this->actingAs($this->pm)->post(route('account.tokens.revoke', $theirs->id))->assertSessionHas('err');
    expect($theirs->fresh()->revoked_at)->toBeNull();

    $this->actingAs($this->pm)->post(route('account.tokens.revoke', $mine->id))->assertSessionHas('ok');
    expect($mine->fresh()->revoked_at)->not->toBeNull()->and(ApiToken::count())->toBe(2);
});

it('forbids client users on the token page', function () {
    $this->actingAs($this->client)->get(route('account.tokens'))->assertForbidden();
    $this->actingAs($this->client)->post(route('account.tokens.store'), ['name' => 'x', 'expiry' => 'none'])->assertForbidden();
    expect(ApiToken::count())->toBe(0);
});

it('rejects the mcp endpoint without a valid token', function () {
    mcRpc($this, null, 'tools/list')->assertStatus(401);
    mcRpc($this, 'cits_'.str_repeat('a', 40), 'tools/list')->assertStatus(401);

    $revoked = $this->svc->create($this->pm, 'r', 'none');
    $this->svc->revoke($this->pm, $revoked['token']->id);
    mcRpc($this, $revoked['plain'], 'tools/list')->assertStatus(401);

    $expired = $this->svc->create($this->pm, 'e', '30');
    $expired['token']->forceFill(['expires_at' => now()->subMinute()])->save();
    mcRpc($this, $expired['plain'], 'tools/list')->assertStatus(401);

    $disabled = $this->svc->create($this->other, 'd', 'none');
    $this->other->forceFill(['status' => 'disabled'])->save();
    mcRpc($this, $disabled['plain'], 'tools/list')->assertStatus(401);
});

it('lists the four tools over http with a valid token and no csrf', function () {
    $tok = $this->svc->create($this->pm, 'ok', 'none');
    $res = mcRpc($this, $tok['plain'], 'tools/list');
    $res->assertOk();
    $names = collect($res->json('result.tools'))->pluck('name')->sort()->values()->all();
    expect($names)->toBe(['get_issue', 'list_issues', 'list_projects', 'list_tickets']);
    expect($tok['token']->fresh()->last_used_at)->not->toBeNull();
});

it('calls a tool over http', function () {
    mcIssue($this->pa, $this->pm, 'MCA-I1', '網路呼叫');
    $tok = $this->svc->create($this->pm, 'ok', 'none');
    $res = mcRpc($this, $tok['plain'], 'tools/call', ['name' => 'list_issues', 'arguments' => []]);
    $res->assertOk();
    expect($res->json('result.content.0.text'))->toContain('MCA-I1');
});

it('lists projects by membership and all for admins', function () {
    CitsServer::actingAs($this->pm)->tool(ListProjectsTool::class)->assertSee('MCA')->assertDontSee('MCB');
    CitsServer::actingAs($this->admin)->tool(ListProjectsTool::class)->assertSee(['MCA', 'MCB']);
});

it('scopes list_issues by project membership and filters', function () {
    mcIssue($this->pa, $this->pm, 'MCA-I1', '登入錯誤', 'pending');
    mcIssue($this->pa, $this->pm, 'MCA-I2', '報表慢', 'closed');
    mcIssue($this->pb, $this->other, 'MCB-I1', '別案問題', 'pending');
    $terminal = app(WorkflowService::class)->statusMap('issue')->filter(fn ($s) => $s->is_terminal)->keys()->first();
    Issue::where('issue_no', 'MCA-I2')->update(['status' => $terminal]);

    $s = CitsServer::actingAs($this->pm);
    $s->tool(ListIssuesTool::class)->assertSee('MCA-I1')->assertDontSee('MCB-I1')->assertDontSee('MCA-I2');
    $s->tool(ListIssuesTool::class, ['open_only' => false])->assertSee(['MCA-I1', 'MCA-I2']);
    $s->tool(ListIssuesTool::class, ['keyword' => '報表', 'open_only' => false])->assertSee('MCA-I2')->assertDontSee('MCA-I1');
    $s->tool(ListIssuesTool::class, ['status' => 'pending'])->assertSee('MCA-I1');
    $s->tool(ListIssuesTool::class, ['stage' => 'DEV'])->assertDontSee('MCA-I1');
    $s->tool(ListIssuesTool::class, ['project' => 'MCB'])->assertHasErrors();
    CitsServer::actingAs($this->admin)->tool(ListIssuesTool::class, ['project' => 'MCB'])->assertSee('MCB-I1');
});

it('filters list_issues by assignee account', function () {
    mcIssue($this->pa, $this->pm, 'MCA-I1', '給我', 'pending', ['assignee_dev_id' => $this->pm->id]);
    mcIssue($this->pa, $this->pm, 'MCA-I2', '沒人', 'pending');
    CitsServer::actingAs($this->pm)->tool(ListIssuesTool::class, ['assignee' => 'mcpm'])->assertSee('MCA-I1')->assertDontSee('MCA-I2');
});

it('returns issue detail with content, tickets and events; hides other projects', function () {
    $i = mcIssue($this->pa, $this->pm, 'MCA-I1', '詳細');
    $t = mcTicket($this->pa, $this->client, 'MCA-T1', '客戶單一');
    DB::table('ticket_issue_links')->insert(['ticket_id' => $t->id, 'issue_id' => $i->id, 'based_on_rev' => 1, 'linked_by' => $this->pm->id, 'linked_at' => now()]);
    ActivityEvent::create(['project_id' => $this->pa->id, 'subject_type' => 'issue', 'subject_id' => $i->id, 'event_type' => 'created', 'actor_id' => $this->pm->id, 'created_at' => now()]);
    mcIssue($this->pb, $this->other, 'MCB-I1', '別案');

    $s = CitsServer::actingAs($this->pm);
    $s->tool(GetIssueTool::class, ['issue_no' => 'MCA-I1'])
        ->assertSee(['第一行', 'MCA-T1', 'N-mcpm', 'created', '第二行'])->assertDontSee('<p>');
    $s->tool(GetIssueTool::class, ['issue_no' => 'MCB-I1'])->assertHasErrors()->assertSee('找不到');
    $s->tool(GetIssueTool::class, ['issue_no' => 'NOPE'])->assertHasErrors();
});

it('requires ticket.view for list_tickets', function () {
    $noTicket = Role::create(['name' => 'mc_issue_only', 'guard_name' => 'web']);
    $noTicket->givePermissionTo('issue.view');
    $u = mcUser('mcnoticket', 'internal', ['mc_issue_only'], [$this->pa]);
    mcTicket($this->pa, $this->client, 'MCA-T1', '單一');
    mcTicket($this->pb, $this->client, 'MCB-T1', '單二');

    CitsServer::actingAs($u)->tool(ListTicketsTool::class)->assertHasErrors();
    CitsServer::actingAs($u)->tool(ListIssuesTool::class)->assertHasNoErrors();
    $s = CitsServer::actingAs($this->pm);
    $s->tool(ListTicketsTool::class)->assertSee('MCA-T1')->assertDontSee('MCB-T1');
    $s->tool(ListTicketsTool::class, ['keyword' => '不存在'])->assertDontSee('MCA-T1');
});

it('never writes', function () {
    $i = mcIssue($this->pa, $this->pm, 'MCA-I1', '唯讀');
    mcTicket($this->pa, $this->client, 'MCA-T1', '唯讀');
    $count = fn () => collect(['issues', 'client_tickets', 'events', 'issue_comments', 'ticket_comments', 'projects', 'users'])
        ->mapWithKeys(fn ($t) => [$t => DB::table($t)->count()])->all();
    $before = $count();
    $updated = $i->fresh()->updated_at;

    $s = CitsServer::actingAs($this->pm);
    $s->tool(ListProjectsTool::class);
    $s->tool(ListIssuesTool::class);
    $s->tool(GetIssueTool::class, ['issue_no' => 'MCA-I1']);
    $s->tool(ListTicketsTool::class);

    expect($count())->toBe($before)->and($i->fresh()->updated_at->equalTo($updated))->toBeTrue();
});
