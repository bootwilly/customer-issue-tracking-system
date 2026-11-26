<?php

use App\Models\Project;
use App\Models\User;
use App\Repositories\SettingRepository;
use Illuminate\Support\Facades\RateLimiter;

function authMakeUser(array $o = []): User
{
    static $n = 0;
    $n++;

    return User::create(array_merge([
        'account' => "u{$n}", 'name' => "User {$n}", 'email' => "u{$n}@example.com",
        'password' => 'secret1', 'user_type' => 'internal', 'status' => 'active',
    ], $o));
}

it('logs in by account', function () {
    authMakeUser(['account' => 'alice']);
    $this->post('/login', ['login' => 'alice', 'password' => 'secret1'])->assertRedirect();
    $this->assertAuthenticated();
});

it('logs in by email case-insensitively', function () {
    authMakeUser(['email' => 'Bob@Example.com']);
    $this->post('/login', ['login' => 'bob@example.com', 'password' => 'secret1']);
    $this->assertAuthenticated();
});

it('rejects wrong password', function () {
    authMakeUser(['account' => 'carol']);
    $this->post('/login', ['login' => 'carol', 'password' => 'nope'])->assertSessionHas('err');
    $this->assertGuest();
});

it('locks out after max attempts', function () {
    RateLimiter::clear('dave|127.0.0.1');
    app(SettingRepository::class)->set('login.max_attempts', '3');
    authMakeUser(['account' => 'dave']);
    for ($i = 0; $i < 3; $i++) {
        $this->post('/login', ['login' => 'dave', 'password' => 'bad']);
    }
    $this->post('/login', ['login' => 'dave', 'password' => 'secret1'])->assertSessionHas('err');
    $this->assertGuest();
    expect(session('err'))->toContain('分鐘');
});

it('blocks disabled users', function () {
    authMakeUser(['account' => 'erin', 'status' => 'disabled']);
    $this->post('/login', ['login' => 'erin', 'password' => 'secret1'])->assertSessionHas('err');
    $this->assertGuest();
});

it('logs out a user disabled mid-session', function () {
    $u = authMakeUser();
    $this->actingAs($u)->get('/account/password')->assertOk();
    $u->update(['status' => 'disabled']);
    $this->actingAs($u->fresh())->get('/account/password')->assertRedirect('/');
    $this->assertGuest();
});

it('enforces password min length from settings', function () {
    $u = authMakeUser();
    $this->actingAs($u)->put('/account/password', [
        'current_password' => 'secret1', 'password' => '12345', 'password_confirmation' => '12345',
    ])->assertSessionHasErrors('password');

    $this->actingAs($u)->put('/account/password', [
        'current_password' => 'secret1', 'password' => '123456', 'password_confirmation' => '123456',
    ])->assertSessionHas('ok');
    expect(Hash::check('123456', $u->fresh()->password))->toBeTrue();
});

it('rejects wrong current password', function () {
    $u = authMakeUser();
    $this->actingAs($u)->put('/account/password', [
        'current_password' => 'wrong', 'password' => '123456', 'password_confirmation' => '123456',
    ])->assertSessionHas('err');
});

it('auto selects the only project after login', function () {
    $u = authMakeUser(['account' => 'frank']);
    $p = Project::create(['code' => 'P1', 'name' => 'One', 'ticket_prefix' => 'A']);
    $p->members()->attach($u->id);
    $this->post('/login', ['login' => 'frank', 'password' => 'secret1'])->assertRedirect('/');
    expect(session('project_id'))->toBe($p->id);
});

it('shows project picker with several projects and switches', function () {
    $u = authMakeUser(['account' => 'gina']);
    $a = Project::create(['code' => 'A', 'name' => 'Alpha', 'ticket_prefix' => 'A']);
    $b = Project::create(['code' => 'B', 'name' => 'Beta', 'ticket_prefix' => 'B']);
    $a->members()->attach($u->id);
    $b->members()->attach($u->id);
    $this->post('/login', ['login' => 'gina', 'password' => 'secret1'])->assertRedirect('/projects/select');
    $this->get('/projects/select')->assertOk()->assertSee('Alpha')->assertSee('Beta');
    $this->post('/projects/select', ['project_id' => $b->id])->assertRedirect('/');
    expect(session('project_id'))->toBe($b->id);
});

it('refuses selecting a project the user is not in', function () {
    $u = authMakeUser();
    $p = Project::create(['code' => 'X', 'name' => 'X', 'ticket_prefix' => 'X']);
    $this->actingAs($u)->post('/projects/select', ['project_id' => $p->id])->assertSessionHas('err');
    expect(session('project_id'))->toBeNull();
});

it('redirects home by user type', function () {
    $this->get('/')->assertOk();
    $this->actingAs(authMakeUser(['user_type' => 'client']))->get('/')->assertRedirect('/tickets');
    $this->actingAs(authMakeUser())->get('/')->assertRedirect('/pm');
});

it('redirects http to https in production and adds HSTS', function () {
    app()->detectEnvironment(fn () => 'production');
    $this->get('/login')->assertStatus(301)->assertRedirectContains('https://');
    $this->post('/login', ['login' => 'x', 'password' => 'y'])->assertStatus(301);
    $this->assertGuest();

    $this->get('https://localhost/')
        ->assertOk()->assertHeader('Strict-Transport-Security');
});

it('uses secure session cookie settings in production', function () {
    $cfg = (function () {
        $_ENV['APP_ENV'] = 'production';
        putenv('APP_ENV=production');
        $c = require config_path('session.php');
        $_ENV['APP_ENV'] = 'testing';
        putenv('APP_ENV=testing');

        return $c;
    })();
    expect($cfg['secure'])->toBeTrue()->and($cfg['http_only'])->toBeTrue()->and($cfg['same_site'])->toBe('lax');
});

it('formats tw() in Taipei time', function () {
    expect(tw('2026-01-01 00:00:00'))->toBe('2026-01-01 08:00')->and(tw(null))->toBe('');
});

it('renders badge from workflow statuses', function () {
    $html = Blade::render('<x-badge status="released" scope="issue"/>');
    expect($html)->toContain('測試通過正式環境已更新完畢');
});
