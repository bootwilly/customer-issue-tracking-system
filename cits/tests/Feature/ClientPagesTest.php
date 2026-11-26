<?php

use App\Models\ClientTicket;
use App\Models\Project;
use App\Models\User;
use Spatie\Permission\PermissionRegistrar;

function clientMakeUser(string $account, array $roles, Project $p): User
{
    $u = User::create(['account' => $account, 'name' => $account, 'email' => "$account@t.test", 'password' => '123456', 'user_type' => 'client', 'status' => 'active']);
    $p->members()->attach($u->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($p->id);
    $u->assignRole($roles);
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);

    return $u->fresh();
}

beforeEach(function () {
    $this->p = Project::create(['code' => 'MES', 'name' => 'MES', 'ticket_prefix' => 'MES']);
    $this->pm = clientMakeUser('cpm', ['client_pm'], $this->p);
    $this->pm2 = clientMakeUser('cpm2', ['client_pm'], $this->p);
    $this->view = clientMakeUser('cview', ['client_view'], $this->p);
    $this->as = fn (User $u) => $this->actingAs($u)->withSession(['project_id' => $this->p->id]);
    $this->payload = ['ticket_type' => 'anomaly', 'anomaly_category' => 'ui', 'title' => '按鈕錯位', 'content_html' => '<p>內容</p>'];
});

it('renders list with tabs and counts', function () {
    ($this->as)($this->pm)->post('/tickets', $this->payload)->assertRedirect();
    ($this->as)($this->pm)->get('/tickets')
        ->assertOk()->assertSee('未結案')->assertSee('待結案')->assertSee('已結案')->assertSee('取消')
        ->assertSee('按鈕錯位')->assertSee('開立新單');
});

it('creates a ticket and shows detail with timeline', function () {
    $res = ($this->as)($this->pm)->post('/tickets', $this->payload);
    $t = ClientTicket::firstOrFail();
    $res->assertRedirect(route('tickets.show', $t->id));
    ($this->as)($this->pm)->get("/tickets/{$t->id}")->assertOk()->assertSee('按鈕錯位')->assertSee('流程時間軸')->assertSee($t->ticket_no);
});

it('forbids view-only user from creating', function () {
    ($this->as)($this->view)->get('/tickets')->assertOk()->assertDontSee('開立新單');
    ($this->as)($this->view)->post('/tickets', $this->payload)->assertForbidden();
    expect(ClientTicket::count())->toBe(0);
});

it('locks edit for second user', function () {
    ($this->as)($this->pm)->post('/tickets', $this->payload);
    $t = ClientTicket::firstOrFail();
    ($this->as)($this->pm)->get("/tickets/{$t->id}/edit")->assertOk()->assertSee('row_version', false);
    ($this->as)($this->pm2)->get("/tickets/{$t->id}/edit")->assertRedirect(route('tickets.show', $t->id))->assertSessionHas('err');
    ($this->as)($this->pm)->postJson("/tickets/{$t->id}/heartbeat")->assertJson(['ok' => true]);
    ($this->as)($this->pm2)->postJson("/tickets/{$t->id}/heartbeat")->assertJson(['ok' => false]);
    ($this->as)($this->pm)->post("/tickets/{$t->id}/release")->assertRedirect();
    ($this->as)($this->pm2)->get("/tickets/{$t->id}/edit")->assertOk();
});

it('saves an edit', function () {
    ($this->as)($this->pm)->post('/tickets', $this->payload);
    $t = ClientTicket::firstOrFail();
    ($this->as)($this->pm)->get("/tickets/{$t->id}/edit");
    ($this->as)($this->pm)->put("/tickets/{$t->id}", array_merge($this->payload, ['title' => '新標題', 'row_version' => $t->fresh()->row_version]))
        ->assertRedirect(route('tickets.show', $t->id));
    expect($t->fresh()->title)->toBe('新標題');
});

it('adds a comment', function () {
    ($this->as)($this->pm)->post('/tickets', $this->payload);
    $t = ClientTicket::firstOrFail();
    ($this->as)($this->pm)->post("/tickets/{$t->id}/comment", ['kind' => 'supplement', 'content_html' => '<p>補充一下</p>'])->assertRedirect();
    ($this->as)($this->pm)->get("/tickets/{$t->id}")->assertSee('補充一下');
});

it('cancels a ticket', function () {
    ($this->as)($this->pm)->post('/tickets', $this->payload);
    $t = ClientTicket::firstOrFail();
    ($this->as)($this->pm)->post("/tickets/{$t->id}/cancel", ['reason' => '重複'])->assertRedirect(route('tickets.show', $t->id))->assertSessionHas('ok');
    expect($t->fresh()->status)->toBe('cancelled');
});

it('lets admin use client ticket pages without leaving read receipts', function () {
    $p = \App\Models\Project::create(['code' => 'ADM', 'name' => 'ADM', 'ticket_prefix' => 'ADM']);
    $c = \App\Models\User::create(['account' => 'admc', 'name' => 'admc', 'email' => 'admc@t.test', 'password' => '123456', 'user_type' => 'client', 'status' => 'active']);
    $p->members()->attach($c->id);
    app(\Spatie\Permission\PermissionRegistrar::class)->setPermissionsTeamId($p->id);
    $c->assignRole('client_pm');
    app(\Spatie\Permission\PermissionRegistrar::class)->setPermissionsTeamId(null);
    $c = $c->fresh();
    $ts = app(\App\Services\TicketService::class);
    $t = $ts->create($c, $p->id, ['ticket_type' => 'anomaly', 'title' => '管理員看單', 'content_html' => '<p>x</p>']);
    $ts->addComment($c, $t->id, $p->id, 'supplement', '<p>補充</p>');
    $admin = \App\Models\User::where('account', 'admin')->first();
    $s = fn () => $this->actingAs($admin)->withSession(['project_id' => $p->id]);

    $s()->get('/tickets')->assertOk()->assertSee('管理員看單')->assertSee('客戶問題')->assertSee('開立新單');
    $s()->get("/tickets/{$t->id}")->assertOk()->assertSee('管理員看單')->assertSee(route('tickets.edit', $t->id));
    $s()->get('/tickets/create')->assertOk();
    expect(\Illuminate\Support\Facades\DB::table('comment_reads')->where('user_id', $admin->id)->count())->toBe(0);
    $own = $ts->create($admin, $p->id, ['ticket_type' => 'anomaly', 'title' => '管理員開單', 'content_html' => '<p>y</p>']);
    expect($own->created_by)->toBe($admin->id);
    $ab = $ts->abilities($t->fresh(), $admin);
    expect($ab['comment'] && $ab['reply'] && $ab['accept'] && $ab['edit'] && $ab['cancel'])->toBeTrue();
});

it('gives a client-type system admin the internal side too', function () {
    $p = \App\Models\Project::create(['code' => 'ADX', 'name' => 'ADX', 'ticket_prefix' => 'ADX']);
    $a = \App\Models\User::create(['account' => 'admx', 'name' => 'admx', 'email' => 'admx@t.test', 'password' => '123456', 'user_type' => 'client', 'is_admin' => true, 'status' => 'active']);
    $s = fn () => $this->actingAs($a)->withSession(['project_id' => $p->id]);

    expect($a->seesInternal())->toBeTrue()->and($a->actsAsClient())->toBeTrue();
    foreach (['/pm', '/dev', '/test', '/timeline', '/reports', '/mes', '/meetings', '/tickets', '/tickets/create'] as $url) {
        $s()->get($url)->assertOk();
    }
    $s()->get('/pm')->assertSee('開發管理')->assertSee('MES 頁面');
});
