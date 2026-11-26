<?php

use App\Models\ClientTicket;
use App\Models\Project;
use App\Models\User;
use Spatie\Permission\PermissionRegistrar;

function adminMakeUser(array $o = []): User
{
    static $n = 0;
    $n++;

    return User::create(array_merge([
        'account' => "x{$n}", 'name' => "X {$n}", 'email' => "x{$n}@example.com",
        'password' => 'secret1', 'user_type' => 'internal', 'status' => 'active',
    ], $o));
}

it('blocks non-admins from /admin', function () {
    $this->get('/admin/users')->assertRedirect('/');
    $this->actingAs(adminMakeUser())->get('/admin/users')->assertForbidden();
});

it('lets admin create, edit and list users', function () {
    $admin = adminMakeUser(['is_admin' => true]);
    $this->actingAs($admin)->post('/admin/users', [
        'account' => 'newbie', 'name' => 'New', 'email' => 'new@example.com',
        'user_type' => 'client', 'status' => 'active', 'password' => 'abcdef',
    ])->assertRedirect('/admin/users');
    $u = User::where('account', 'newbie')->first();
    expect($u->isClient())->toBeTrue();

    $this->actingAs($admin)->put("/admin/users/{$u->id}", [
        'account' => 'newbie', 'name' => 'Renamed', 'email' => 'new@example.com',
        'user_type' => 'client', 'status' => 'disabled', 'password' => '',
    ])->assertRedirect('/admin/users');
    expect($u->fresh()->status)->toBe('disabled')->and(Hash::check('abcdef', $u->fresh()->password))->toBeTrue();

    $this->actingAs($admin)->get('/admin/users')->assertOk()->assertSee('Renamed');
});

it('enforces password min length when admin creates user', function () {
    $admin = adminMakeUser(['is_admin' => true]);
    $this->actingAs($admin)->post('/admin/users', [
        'account' => 'short', 'name' => 'S', 'email' => 's@example.com',
        'user_type' => 'internal', 'status' => 'active', 'password' => '123',
    ])->assertSessionHasErrors('password');
});

it('prevents admin from disabling self', function () {
    $admin = adminMakeUser(['is_admin' => true]);
    $this->actingAs($admin)->put("/admin/users/{$admin->id}", [
        'account' => $admin->account, 'name' => 'X', 'email' => $admin->email,
        'user_type' => 'internal', 'status' => 'disabled',
    ])->assertSessionHas('err');
    expect($admin->fresh()->status)->toBe('active');
});

it('creates and edits projects', function () {
    $admin = adminMakeUser(['is_admin' => true]);
    $this->actingAs($admin)->post('/admin/projects', [
        'code' => 'MES1', 'name' => 'MES Project', 'ticket_prefix' => 'MES', 'status' => 'active',
    ])->assertRedirect('/admin/projects');
    $p = Project::where('code', 'MES1')->first();
    $this->actingAs($admin)->put("/admin/projects/{$p->id}", [
        'code' => 'MES1', 'name' => 'MES2', 'ticket_prefix' => 'MES', 'status' => 'inactive',
    ])->assertRedirect('/admin/projects');
    expect($p->fresh()->name)->toBe('MES2');
});

it('assigns roles per project and filters by user type', function () {
    $admin = adminMakeUser(['is_admin' => true]);
    $p1 = Project::create(['code' => 'P1', 'name' => 'P1', 'ticket_prefix' => 'A']);
    $p2 = Project::create(['code' => 'P2', 'name' => 'P2', 'ticket_prefix' => 'B']);
    $client = adminMakeUser(['user_type' => 'client']);
    $dev = adminMakeUser();

    foreach ([$p1, $p2] as $p) {
        $this->actingAs($admin)->post("/admin/projects/{$p->id}/members", ['user_id' => $client->id])->assertSessionHas('ok');
        $this->actingAs($admin)->post("/admin/projects/{$p->id}/members", ['user_id' => $dev->id]);
    }

    $this->actingAs($admin)->put("/admin/projects/{$p1->id}/members/{$client->id}", ['roles' => ['client_pm']])->assertSessionHas('ok');
    $this->actingAs($admin)->put("/admin/projects/{$p2->id}/members/{$client->id}", ['roles' => ['client_view']]);
    // wrong type is refused
    $this->actingAs($admin)->put("/admin/projects/{$p1->id}/members/{$client->id}", ['roles' => ['dev']])->assertSessionHas('err');
    $this->actingAs($admin)->put("/admin/projects/{$p1->id}/members/{$dev->id}", ['roles' => ['client_pm']])->assertSessionHas('err');
    $this->actingAs($admin)->put("/admin/projects/{$p1->id}/members/{$dev->id}", ['roles' => ['dev']])->assertSessionHas('ok');

    $reg = app(PermissionRegistrar::class);
    $reg->setPermissionsTeamId($p1->id);
    $c = User::find($client->id);
    expect($c->can('ticket.create'))->toBeTrue();
    $reg->setPermissionsTeamId($p2->id);
    $c = User::find($client->id);
    expect($c->can('ticket.create'))->toBeFalse()->and($c->can('ticket.view'))->toBeTrue();

    $reg->setPermissionsTeamId($p1->id);
    expect(User::find($dev->id)->can('dev.manage'))->toBeTrue();
    $reg->setPermissionsTeamId($p2->id);
    expect(User::find($dev->id)->can('dev.manage'))->toBeFalse();

    $this->actingAs($admin)->get("/admin/projects/{$p1->id}/members")->assertOk()->assertSee($client->account);
});

it('applies the selected project permissions in requests', function () {
    $admin = adminMakeUser(['is_admin' => true]);
    $p = Project::create(['code' => 'P1', 'name' => 'P1', 'ticket_prefix' => 'A']);
    $client = adminMakeUser(['user_type' => 'client']);
    $p->members()->attach($client->id);
    $this->actingAs($admin)->put("/admin/projects/{$p->id}/members/{$client->id}", ['roles' => ['client_pm']]);

    $this->actingAs($client)->get('/account/password')->assertOk()->assertDontSee('客戶問題</a>');
    $this->actingAs(User::find($client->id))->withSession(['project_id' => $p->id])->get('/account/password')
        ->assertOk()->assertSee('客戶問題');
});

it('removes member and their roles', function () {
    $admin = adminMakeUser(['is_admin' => true]);
    $p = Project::create(['code' => 'P1', 'name' => 'P1', 'ticket_prefix' => 'A']);
    $dev = adminMakeUser();
    $this->actingAs($admin)->post("/admin/projects/{$p->id}/members", ['user_id' => $dev->id]);
    $this->actingAs($admin)->put("/admin/projects/{$p->id}/members/{$dev->id}", ['roles' => ['dev']]);
    $this->actingAs($admin)->delete("/admin/projects/{$p->id}/members/{$dev->id}")->assertSessionHas('ok');
    expect($p->members()->count())->toBe(0);
    $this->assertDatabaseMissing('model_has_roles', ['model_id' => $dev->id]);
});

it('deletes a user without history', function () {
    $admin = adminMakeUser(['is_admin' => true]);
    $u = adminMakeUser();
    $this->actingAs($admin)->delete("/admin/users/{$u->id}")->assertSessionHas('ok');
    $this->assertDatabaseMissing('users', ['id' => $u->id]);
});

it('blocks deleting a user with history', function () {
    $admin = adminMakeUser(['is_admin' => true]);
    $u = adminMakeUser(['user_type' => 'client']);
    $p = Project::create(['code' => 'P1', 'name' => 'P1', 'ticket_prefix' => 'A']);
    ClientTicket::create([
        'project_id' => $p->id, 'ticket_no' => 'A-1', 'ticket_type' => 'bug', 'title' => 't',
        'content_html' => '<p>x</p>', 'status' => 'submitted', 'created_by' => $u->id,
    ]);
    $this->actingAs($admin)->delete("/admin/users/{$u->id}")->assertSessionHas('err');
    $this->assertDatabaseHas('users', ['id' => $u->id]);
});

it('blocks deleting self', function () {
    $admin = adminMakeUser(['is_admin' => true]);
    $this->actingAs($admin)->delete("/admin/users/{$admin->id}")->assertSessionHas('err');
});
