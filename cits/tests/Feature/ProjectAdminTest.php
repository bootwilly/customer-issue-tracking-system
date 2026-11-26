<?php

use App\Models\ActivityEvent;
use App\Models\Project;
use App\Models\User;
use Spatie\Permission\PermissionRegistrar;

function padUser(string $account, string $type, array $roles, ?Project $p): User
{
    $u = User::create(['account' => $account, 'name' => $account, 'email' => "$account@t.test", 'password' => '123456', 'user_type' => $type, 'status' => 'active']);
    if ($p) {
        $p->members()->attach($u->id);
        app(PermissionRegistrar::class)->setPermissionsTeamId($p->id);
        $u->assignRole($roles);
        app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    }

    return $u->fresh();
}

function padRoles(User $u, Project $p): array
{
    app(PermissionRegistrar::class)->setPermissionsTeamId($p->id);
    $r = $u->fresh()->getRoleNames()->sort()->values()->all();
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);

    return $r;
}

beforeEach(function () {
    $this->withoutVite();
    $this->p = Project::create(['code' => 'PA', 'name' => 'PA', 'ticket_prefix' => 'PA']);
    $this->other = Project::create(['code' => 'PB', 'name' => 'PB', 'ticket_prefix' => 'PB']);
    $this->padm = padUser('paadm', 'internal', ['project_admin'], $this->p);
    $this->padm2 = padUser('paadm2', 'internal', ['project_admin', 'dev'], $this->p);
    $this->ipm = padUser('paipm', 'internal', ['internal_pm'], $this->p);
    $this->dev = padUser('padev', 'internal', ['dev'], $this->p);
    $this->newbie = padUser('panew', 'client', [], null);
    $this->as = fn (User $u, ?Project $p = null) => $this->actingAs($u)->withSession(['project_id' => ($p ?? $this->p)->id]);
});

it('shows the project admin menu only to project admins', function () {
    ($this->as)($this->padm)->get(route('flows.index'))->assertSee('專案管理');
    ($this->as)($this->ipm)->get(route('flows.index'))->assertDontSee('專案管理');
    foreach (['padmin.members', 'padmin.trash', 'padmin.events'] as $r) {
        ($this->as)($this->ipm)->get(route($r))->assertForbidden();
        ($this->as)($this->padm)->get(route($r))->assertOk();
    }
    // project admin of another project has no rights here
    $foreign = padUser('paforeign', 'internal', ['project_admin'], $this->other);
    ($this->as)($foreign, $this->other)->get(route('padmin.members'))->assertOk()->assertDontSee(route('padmin.members.update', $this->dev->id), false);
    ($this->as)($foreign, $this->other)->delete(route('padmin.members.destroy', $this->dev->id))->assertSessionHas('err');
    expect($this->p->members()->where('users.id', $this->dev->id)->exists())->toBeTrue();
});

it('adds existing accounts, assigns roles and removes members', function () {
    ($this->as)($this->padm)->get(route('padmin.members'))->assertSee('panew')->assertDontSee('value="project_admin"', false);
    ($this->as)($this->padm)->post(route('padmin.members.store'), ['user_id' => $this->newbie->id])->assertSessionHas('ok');
    expect($this->p->members()->where('users.id', $this->newbie->id)->exists())->toBeTrue();
    ($this->as)($this->padm)->post(route('padmin.members.store'), ['user_id' => $this->newbie->id])->assertSessionHas('err');

    ($this->as)($this->padm)->put(route('padmin.members.update', $this->newbie->id), ['roles' => ['client_view']])->assertSessionHas('ok');
    expect(padRoles($this->newbie, $this->p))->toBe(['client_view']);
    ($this->as)($this->padm)->put(route('padmin.members.update', $this->newbie->id), ['roles' => ['dev']])->assertSessionHas('err'); // wrong user type

    ($this->as)($this->padm)->delete(route('padmin.members.destroy', $this->dev->id))->assertSessionHas('ok');
    expect($this->p->members()->where('users.id', $this->dev->id)->exists())->toBeFalse()->and(padRoles($this->dev, $this->p))->toBe([]);

    expect(ActivityEvent::where('subject_type', 'member')->orderBy('id')->pluck('event_type')->all())->toBe(['member_added', 'member_roles_changed', 'member_removed']);
    ($this->as)($this->padm)->get(route('padmin.events'))->assertSee('加入成員')->assertSee('變更角色')->assertSee('移出成員');
});

it('cannot escalate: no granting project admin, no touching itself or other project admins', function () {
    ($this->as)($this->padm)->put(route('padmin.members.update', $this->ipm->id), ['roles' => ['internal_pm', 'project_admin']])
        ->assertSessionHas('err', '這個角色只能由系統管理員指派。');
    expect(padRoles($this->ipm, $this->p))->toBe(['internal_pm']);

    ($this->as)($this->padm)->put(route('padmin.members.update', $this->padm->id), ['roles' => ['internal_pm']])->assertSessionHas('err');
    ($this->as)($this->padm)->delete(route('padmin.members.destroy', $this->padm->id))->assertSessionHas('err');
    ($this->as)($this->padm)->put(route('padmin.members.update', $this->padm2->id), ['roles' => ['dev']])->assertSessionHas('err');
    ($this->as)($this->padm)->delete(route('padmin.members.destroy', $this->padm2->id))->assertSessionHas('err');
    expect(padRoles($this->padm2, $this->p))->toBe(['dev', 'project_admin']);

    // inactive accounts cannot be added
    $this->newbie->update(['status' => 'disabled']);
    ($this->as)($this->padm)->post(route('padmin.members.store'), ['user_id' => $this->newbie->id])->assertSessionHas('err');
});

it('still lets the system admin assign project admins', function () {
    $admin = User::create(['account' => 'paroot', 'name' => 'root', 'email' => 'root@t.test', 'password' => '123456', 'user_type' => 'internal', 'is_admin' => true, 'status' => 'active']);
    $this->actingAs($admin)->put(route('admin.projects.members.update', [$this->p, $this->dev]), ['roles' => ['dev', 'project_admin']])->assertSessionHas('ok');
    expect(padRoles($this->dev, $this->p))->toBe(['dev', 'project_admin']);
});
