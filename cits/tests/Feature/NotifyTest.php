<?php

use App\Models\Project;
use App\Models\SystemSetting;
use App\Models\User;
use App\Models\UserNotification;
use App\Repositories\EventRepository;
use App\Repositories\SettingRepository;
use App\Services\NotificationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;

function ntUser(array $o = []): User
{
    static $n = 0;
    $n++;

    return User::create(array_merge([
        'account' => "nt{$n}", 'name' => "NT {$n}", 'email' => "nt{$n}@example.com",
        'password' => 'secret1', 'user_type' => 'internal', 'status' => 'active',
    ], $o));
}

function ntMailOn(bool $on = true): void
{
    $s = app(SettingRepository::class);
    $s->set('mail.enabled', $on ? '1' : '0');
    $s->set('mail.host', 'smtp.test');
    $s->set('mail.port', '587');
    $s->set('mail.encryption', 'tls');
    $s->set('mail.from_address', 'noreply@example.com');
    // Capture mails in memory instead of opening SMTP sockets.
    config(['mail.mailers.smtp.transport' => 'array']);
}

function ntSentCount(): int
{
    return count(app('mail.manager')->mailer('smtp')->getSymfonyTransport()->messages());
}

function ntNotif(User $u, string $title = 'T', ?string $url = '/issues/5'): UserNotification
{
    return UserNotification::create(['user_id' => $u->id, 'title' => $title, 'url' => $url, 'created_at' => now()]);
}

it('restricts mail settings to admins', function () {
    $this->actingAs(ntUser())->get('/admin/settings/mail')->assertForbidden();
    $this->actingAs(ntUser())->put('/admin/settings/mail', [])->assertForbidden();
    $this->actingAs(ntUser(['is_admin' => true]))->get('/admin/settings/mail')->assertOk();
});

it('stores smtp password encrypted and never renders it', function () {
    Project::create(['code' => 'P1', 'name' => 'P1', 'ticket_prefix' => 'A']);
    $admin = ntUser(['is_admin' => true]);
    $payload = ['enabled' => 1, 'host' => 'smtp.test', 'port' => 587, 'encryption' => 'tls', 'username' => 'u', 'password' => 'S3cretPw', 'from_address' => 'a@example.com', 'from_name' => 'X'];
    $this->actingAs($admin)->put('/admin/settings/mail', $payload)->assertRedirect();

    $row = SystemSetting::find('mail.password');
    expect($row->is_encrypted)->toBeTrue()->and($row->value)->not->toContain('S3cretPw');
    expect(app(SettingRepository::class)->get('mail.password'))->toBe('S3cretPw');
    $this->actingAs($admin)->get('/admin/settings/mail')->assertOk()->assertDontSee('S3cretPw')->assertSee('已設定');

    // blank keeps old
    $this->actingAs($admin)->put('/admin/settings/mail', array_merge($payload, ['password' => '']));
    expect(app(SettingRepository::class)->get('mail.password'))->toBe('S3cretPw');

    // event logged without password
    $payloadJson = DB::table('events')->where('subject_type', 'system')->pluck('payload')->implode('|');
    expect($payloadJson)->not->toContain('S3cretPw');

    // clear
    $this->actingAs($admin)->put('/admin/settings/mail', array_merge($payload, ['password' => '', 'clear_password' => 1]));
    expect(app(SettingRepository::class)->get('mail.password'))->toBeNull();
});

it('sends a test mail and reports errors', function () {
    ntMailOn();
    $admin = ntUser(['is_admin' => true]);
    $this->actingAs($admin)->post('/admin/settings/mail/test', ['to' => 'dest@example.com'])
        ->assertSessionHas('ok');
    expect(ntSentCount())->toBe(1);

    config(['mail.mailers.smtp.transport' => 'smtp']);
    app(SettingRepository::class)->set('mail.host', '127.0.0.1');
    app(SettingRepository::class)->set('mail.port', '1');
    $this->actingAs($admin)->post('/admin/settings/mail/test', ['to' => 'dest@example.com'])
        ->assertSessionHas('err');
});

it('shows bell count, marks read and read-all, and isolates users', function () {
    $a = ntUser();
    $b = ntUser();
    $n1 = ntNotif($a);
    ntNotif($a);
    $other = ntNotif($b);

    $this->actingAs($a)->get('/notifications')->assertOk()->assertSee('title="通知"', false);
    expect(app(NotificationService::class)->unreadCount($a))->toBe(2);

    $this->actingAs($a)->post("/notifications/{$n1->id}/read")->assertRedirect(url('/issues/5'));
    expect($n1->fresh()->read_at)->not->toBeNull();
    $this->actingAs($a)->post("/notifications/{$other->id}/read")->assertNotFound();
    expect($other->fresh()->read_at)->toBeNull();

    $this->actingAs($a)->get('/notifications?filter=unread')->assertOk();
    $this->actingAs($a)->post('/notifications/read-all')->assertRedirect();
    expect(app(NotificationService::class)->unreadCount($a))->toBe(0)
        ->and($other->fresh()->read_at)->toBeNull();
});

it('forgot password is neutral, creates token and mails when enabled', function () {
    ntMailOn();
    $u = ntUser();
    $r1 = $this->post('/password/forgot', ['login' => $u->account]);
    $r2 = $this->post('/password/forgot', ['login' => 'nobody']);
    expect(session('ok'))->not->toBeNull();
    $r1->assertRedirect(route('password.forgot'));
    $r2->assertRedirect(route('password.forgot'));
    expect(DB::table('password_reset_tokens')->where('email', $u->email)->count())->toBe(1)
        ->and(DB::table('password_reset_tokens')->count())->toBe(1)
        ->and(ntSentCount())->toBe(1);
});

it('does nothing for forgot password when mail is disabled', function () {
    ntMailOn(false);
    $u = ntUser();
    $this->get('/password/forgot')->assertOk()->assertSee('系統未啟用郵件，請聯絡管理員重設密碼。');
    $this->post('/password/forgot', ['login' => $u->account])->assertRedirect(route('password.forgot'));
    expect(DB::table('password_reset_tokens')->count())->toBe(0);
});

it('throttles forgot password', function () {
    ntMailOn();
    RateLimiter::clear('pwd-forgot|127.0.0.1');
    for ($i = 0; $i < 5; $i++) {
        $this->post('/password/forgot', ['login' => 'x'])->assertSessionHas('ok');
    }
    $this->post('/password/forgot', ['login' => 'x'])->assertSessionHas('err');
});

it('resets password with token and enforces min length', function () {
    ntMailOn();
    $u = ntUser();
    $token = Password::broker()->createToken($u);

    $this->post("/password/reset/{$token}", ['email' => $u->email, 'password' => '123', 'password_confirmation' => '123'])
        ->assertSessionHasErrors('password');
    $this->post('/password/reset/bad', ['email' => $u->email, 'password' => 'newpass1', 'password_confirmation' => 'newpass1'])
        ->assertSessionHas('err');
    $this->post("/password/reset/{$token}", ['email' => $u->email, 'password' => 'newpass1', 'password_confirmation' => 'newpass1'])
        ->assertRedirect(route('home'));
    expect(Hash::check('newpass1', $u->fresh()->password))->toBeTrue()
        ->and(DB::table('password_reset_tokens')->count())->toBe(0);
});

it('skips outbox rows for opted-out users but keeps in-app notifications', function () {
    $p = Project::create(['code' => 'P1', 'name' => 'P1', 'ticket_prefix' => 'A']);
    $on = ntUser();
    $off = ntUser(['email_notify' => false]);
    $p->members()->attach([$on->id, $off->id]);
    app(EventRepository::class)->notify([$on->id, $off->id], $p->id, 'Hello', '/issues/1', true);

    expect(UserNotification::count())->toBe(2)
        ->and(DB::table('notification_outbox')->where('user_id', $off->id)->count())->toBe(0)
        ->and(DB::table('notification_outbox')->where('user_id', $on->id)->count())->toBe(1);
});

it('lets a user toggle email notifications in account settings', function () {
    $u = ntUser();
    $this->actingAs($u)->get('/account/settings')->assertOk()->assertSee('以 Email 接收通知');
    $this->actingAs($u)->put('/account/settings', [])->assertRedirect();
    expect($u->fresh()->email_notify)->toBeFalse();
    $this->actingAs($u)->put('/account/settings', ['email_notify' => 1]);
    expect($u->fresh()->email_notify)->toBeTrue();
});
