<?php

use App\Models\ApiToken;
use App\Models\Attachment;
use App\Models\Issue;
use App\Models\MesPage;
use App\Models\Project;
use App\Models\SystemSetting;
use App\Models\User;
use App\Models\UserNotification;
use App\Repositories\EventRepository;
use App\Repositories\ProjectRepository;
use App\Services\AdminService;
use App\Services\ApiTokenService;
use App\Services\ProjectAdminService;
use App\Services\TicketService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\PermissionRegistrar;

// second batch for docs/security-audit-20261127.md: M-01, M-02 (rest), M-03, M-04, L-01

function sbUser(string $account, string $type, array $roles = [], ?Project $p = null, array $extra = []): User
{
    $u = User::create($extra + ['account' => $account, 'name' => $account, 'email' => "$account@sb.test", 'password' => 'secret1', 'user_type' => $type, 'status' => 'active']);
    if ($p) {
        $p->members()->attach($u->id);
        app(PermissionRegistrar::class)->setPermissionsTeamId($p->id);
        $u->assignRole($roles);
        app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    }

    return $u->fresh();
}

beforeEach(function () {
    $this->withoutVite();
    $this->p = Project::create(['code' => 'SC', 'name' => 'SC', 'ticket_prefix' => 'SC']);
    $this->q = Project::create(['code' => 'SD', 'name' => 'SD', 'ticket_prefix' => 'SD']);
    $this->as = fn (User $u, ?Project $p = null) => $this->actingAs($u)->withSession(['project_id' => ($p ?? $this->p)->id]);
});

it('M-01 hides other customers from project admins and protects system admins', function () {
    $padm = sbUser('sbpadm', 'internal', ['project_admin'], $this->p);
    $foreign = sbUser('sbforeign', 'client', ['client_pm'], $this->q);
    $fresh = sbUser('sbfresh', 'client');
    $internal = sbUser('sbint', 'internal');
    $svc = app(ProjectAdminService::class);

    $ids = $svc->candidates($padm, $this->p->id)->pluck('id')->all();
    expect($ids)->toContain($fresh->id, $internal->id)->not->toContain($foreign->id);
    expect(fn () => $svc->addMember($padm, $this->p->id, $foreign->id))->toThrow(Exception::class);

    $admin = sbUser('sbadmin', 'internal', ['dev'], $this->p, ['is_admin' => true]);
    $row = $svc->members($padm, $this->p->id)->first(fn ($m) => $m['user']->id === $admin->id);
    expect($row['locked'])->toBeTrue();
    expect(fn () => $svc->removeMember($padm, $this->p->id, $admin->id))->toThrow(Exception::class);
});

it('M-04 drops roles, tokens and issue watches of the old user type', function () {
    $u = sbUser('sbswitch', 'internal', ['internal_pm'], $this->p);
    app(ApiTokenService::class)->create($u, 't', 'none');
    $issue = Issue::create(['project_id' => $this->p->id, 'issue_no' => 'SC-1', 'origin' => 'pm', 'title' => 'x', 'content_html' => '<p>x</p>',
        'stage' => 'PM', 'status' => 'new', 'created_by' => $u->id]);
    DB::table('issue_watchers')->insert(['issue_id' => $issue->id, 'user_id' => $u->id, 'created_at' => now()]);
    $admin = sbUser('sbadm2', 'internal', [], null, ['is_admin' => true]);

    app(AdminService::class)->updateUser($u, ['account' => $u->account, 'name' => $u->name, 'email' => $u->email, 'user_type' => 'client', 'status' => 'active'], $admin);

    expect(app(ProjectRepository::class)->roleNames($u->fresh(), $this->p->id))->toBe([])
        ->and(ApiToken::where('user_id', $u->id)->whereNull('revoked_at')->count())->toBe(0)
        ->and(DB::table('issue_watchers')->where('user_id', $u->id)->count())->toBe(0);
});

it('M-04 does not notify people who left the project', function () {
    $in = sbUser('sbin', 'internal', ['dev'], $this->p);
    $gone = sbUser('sbgone', 'internal');
    app(EventRepository::class)->notify([$in->id, $gone->id], $this->p->id, 'hi', '/issues/1', false);
    expect(UserNotification::pluck('user_id')->all())->toBe([$in->id]);
});

it('L-01 signs other sessions out after a password change and keeps the changing one', function () {
    $u = sbUser('sbpwd', 'internal', ['dev'], $this->p);
    ($this->as)($u)->get('/account/password')->assertOk();

    // changed elsewhere (another device / reset link): this session is signed out on its next request
    $u->update(['password' => 'other99']);
    ($this->as)($u->fresh())->get('/account/password')->assertRedirect(route('home'))->assertSessionHas('err');
    $this->assertGuest();

    // changed here: still signed in afterwards
    $v = sbUser('sbpwd2', 'internal', ['dev'], $this->p);
    ($this->as)($v)->get('/account/password')->assertOk();
    $this->put('/account/password', ['current_password' => 'secret1', 'password' => 'newpass888', 'password_confirmation' => 'newpass888'])->assertSessionHas('ok');
    $this->get('/account/password')->assertOk();
});

it('L-01 throttles failed MCP tokens per client', function () {
    RateLimiter::clear('mcp-fail:127.0.0.1');
    $call = fn () => $this->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'],
        ['Accept' => 'application/json, text/event-stream', 'Authorization' => 'Bearer cits_'.str_repeat('z', 40)]);
    foreach (range(1, 20) as $n) {
        $call()->assertStatus(401);
    }
    $call()->assertStatus(429);
});

it('M-03 refuses MES pages and tickets of other projects without naming them', function () {
    $pm = sbUser('sbpm', 'internal', ['internal_pm'], $this->p);
    $client = sbUser('sbcli', 'client', ['client_pm'], $this->p);
    $otherClient = sbUser('sbcli2', 'client', ['client_pm'], $this->q);
    $page = MesPage::create(['project_id' => $this->q->id, 'node_type' => 'page', 'name' => 'x', 'page_path' => '~/x.aspx', 'page_file' => 'x.aspx', 'sort' => 0, 'is_active' => true]);
    $mk = fn (User $c, Project $p) => app(TicketService::class)->create($c, $p->id, ['ticket_type' => 'anomaly', 'anomaly_category' => 'ui', 'title' => 't', 'content_html' => '<p>x</p>']);

    ($this->as)($pm)->post('/pm/issues', ['title' => 'i', 'severity' => 2, 'content_html' => '<p>x</p>', 'mes_page_id' => $page->id])->assertSessionHas('err');
    expect(Issue::count())->toBe(0);

    $t = $mk($client, $this->p);
    $foreign = $mk($otherClient, $this->q);
    ($this->as)($pm)->post("/pm/tickets/{$t->id}/accept");
    $res = ($this->as)($pm)->post("/pm/tickets/{$t->id}/issue", ['title' => 'i', 'severity' => 3, 'content_html' => '<p>i</p>', 'extra_ticket_ids' => [$foreign->id]]);
    $res->assertSessionHas('err');
    expect(session('err'))->not->toContain($foreign->ticket_no)
        ->and(Issue::count())->toBe(0);
    ($this->as)($pm)->post("/pm/tickets/{$t->id}/issue", ['title' => 'i', 'severity' => 3, 'content_html' => '<p>i</p>', 'extra_ticket_ids' => [999999]])->assertSessionHas('err');
});

it('M-02 strips path separators from names and completes an upload only once', function () {
    Storage::fake('local');
    SystemSetting::query()->updateOrCreate(['key' => 'attachment.max_mb'], ['value' => '1']);
    $u = sbUser('sbup', 'internal');
    $id = $this->actingAs($u)->postJson('/uploads/init', ['name' => "../..\\a/b\r\n.txt", 'size' => 5])->assertOk()->json('id');
    $this->actingAs($u)->postJson("/uploads/$id/chunk", ['index' => 0, 'chunk' => UploadedFile::fake()->createWithContent('0.part', 'hello')])->assertOk();
    $aid = $this->actingAs($u)->postJson("/uploads/$id/complete")->assertOk()->json('attachment_id');
    expect(Attachment::find($aid)->original_name)->toBe('.._.._a_b.txt');
    $this->actingAs($u)->get("/attachments/$aid")->assertOk();
    $this->actingAs($u)->postJson("/uploads/$id/complete")->assertStatus(422);
    expect(Attachment::count())->toBe(1);
});
