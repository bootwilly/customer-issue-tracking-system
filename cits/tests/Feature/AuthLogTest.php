<?php

use App\Models\AuthLog;
use App\Models\User;
use App\Repositories\SettingRepository;
use Illuminate\Support\Facades\RateLimiter;

// sign-in audit trail: every auth event with source IP, admin list / export, retention purge

function alUser(array $o = []): User
{
    return User::create(array_merge([
        'account' => 'erin', 'name' => 'Erin', 'email' => 'erin@al.test',
        'password' => 'secret1', 'user_type' => 'internal', 'status' => 'active',
    ], $o));
}

function alLast(): AuthLog
{
    return AuthLog::orderByDesc('id')->firstOrFail();
}

it('logs successful and failed logins with ip, forwarded-for and user agent', function () {
    $u = alUser();
    $this->withHeaders(['X-Forwarded-For' => '203.0.113.9', 'User-Agent' => 'PestBrowser/1'])
        ->post('/login', ['login' => 'erin', 'password' => 'secret1'])->assertRedirect();
    $l = alLast();
    expect($l->event)->toBe('login_ok')->and($l->user_id)->toBe($u->id)
        ->and($l->ip)->toBe('203.0.113.9')->and($l->forwarded_for)->toBe('203.0.113.9')
        ->and($l->user_agent)->toBe('PestBrowser/1');

    $this->post('/logout');
    expect(alLast()->event)->toBe('logout');

    $this->post('/login', ['login' => 'erin', 'password' => 'bad']);
    expect(alLast()->only(['event', 'detail', 'user_id']))->toBe(['event' => 'login_fail', 'detail' => 'wrong_password', 'user_id' => $u->id]);

    $this->post('/login', ['login' => 'nobody', 'password' => 'bad']);
    expect(alLast()->only(['event', 'detail', 'user_id', 'login']))->toBe(['event' => 'login_fail', 'detail' => 'unknown_user', 'user_id' => null, 'login' => 'nobody']);
});

it('logs lockout, disabled accounts and password changes', function () {
    RateLimiter::clear('erin|127.0.0.1');
    app(SettingRepository::class)->set('login.max_attempts', '2');
    $u = alUser();
    foreach (range(1, 3) as $i) {
        $this->post('/login', ['login' => 'erin', 'password' => 'bad']);
    }
    expect(alLast()->event)->toBe('login_locked');
    RateLimiter::clear('erin|127.0.0.1');

    $this->actingAs($u)->put('/account/password', ['current_password' => 'secret1', 'password' => 'secret2', 'password_confirmation' => 'secret2']);
    expect(alLast()->only(['event', 'detail']))->toBe(['event' => 'password_changed', 'detail' => null]);

    $u->forceFill(['status' => 'disabled'])->save();
    $this->get('/account/password')->assertRedirect(route('home'));
    expect(alLast()->only(['event', 'user_id']))->toBe(['event' => 'disabled_kick', 'user_id' => $u->id]);
});

it('logs the idle sign-out', function () {
    $u = alUser();
    $this->actingAs($u)->get('/account/password')->assertOk();
    $this->travel(61)->minutes();
    $this->get('/account/password')->assertRedirect(route('home'));
    expect(alLast()->only(['event', 'user_id']))->toBe(['event' => 'idle_logout', 'user_id' => $u->id]);
});

it('shows and exports the log to system admins only', function () {
    $admin = alUser(['account' => 'root', 'email' => 'root@al.test', 'is_admin' => true]);
    $u = alUser();
    $this->post('/login', ['login' => 'erin', 'password' => 'bad']);

    $this->actingAs($u)->get('/admin/auth-logs')->assertForbidden();
    $this->actingAs($admin)->get('/admin/auth-logs?event=login_fail')->assertOk()->assertSee('登入失敗')->assertSee('密碼錯誤');
    $this->get('/admin/auth-logs?ip=10.')->assertOk()->assertSee('沒有紀錄');
    $this->get('/admin/users/'.$u->id.'/edit')->assertOk()->assertSee('登入紀錄')->assertSee('127.0.0.1');
    $res = $this->get('/admin/auth-logs/export');
    $res->assertOk();
    expect($res->headers->get('content-disposition'))->toContain('.xlsx');
});

it('purges rows older than the retention setting', function () {
    $u = alUser();
    AuthLog::create(['user_id' => $u->id, 'event' => 'login_ok', 'created_at' => now()->subDays(200)]);
    AuthLog::create(['user_id' => $u->id, 'event' => 'login_ok', 'created_at' => now()->subDays(10)]);
    $this->artisan('cits:purge-auth-logs')->assertSuccessful();
    expect(AuthLog::count())->toBe(1);

    app(SettingRepository::class)->set('auth_log.keep_days', '0');
    AuthLog::create(['user_id' => $u->id, 'event' => 'login_ok', 'created_at' => now()->subDays(900)]);
    $this->artisan('cits:purge-auth-logs')->assertSuccessful();
    expect(AuthLog::count())->toBe(2);
});
