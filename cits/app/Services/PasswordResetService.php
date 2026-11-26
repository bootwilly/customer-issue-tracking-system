<?php

namespace App\Services;

use App\Repositories\SettingRepository;
use App\Repositories\UserRepository;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\RateLimiter;
use Throwable;

class PasswordResetService
{
    private const MAX_REQUESTS = 5;

    private const DECAY_SECONDS = 900;

    public function __construct(
        private SettingRepository $settings,
        private UserRepository $users,
        private OutboxService $outbox,
        private AuthService $auth,
    ) {}

    public function mailEnabled(): bool
    {
        return $this->settings->get('mail.enabled', '0') === '1';
    }

    public function minLength(): int
    {
        return $this->auth->passwordMinLength();
    }

    /** @return bool false when throttled */
    public function request(string $login, string $ip): bool
    {
        $key = 'pwd-forgot|'.$ip;
        if (RateLimiter::tooManyAttempts($key, self::MAX_REQUESTS)) {
            return false;
        }
        RateLimiter::hit($key, self::DECAY_SECONDS);

        if (! $this->mailEnabled()) {
            return true;
        }
        $user = $this->users->findByLogin($login);
        if (! $user || ! $user->isActive()) {
            return true;
        }

        try {
            $token = Password::broker()->createToken($user);
            $link = route('password.reset', ['token' => $token, 'email' => $user->email]);
            $this->outbox->applySmtpConfig();
            Mail::mailer('smtp')->html(
                '<p>您好，'.e($user->name).'：</p><p>請於 60 分鐘內點擊以下連結重設密碼：</p><p><a href="'.e($link).'">'.e($link).'</a></p><p>若非本人操作，請忽略此信。</p>',
                fn ($m) => $m->to($user->email)->subject('【CITS】重設密碼')
            );
        } catch (Throwable $e) {
            Log::warning('password reset mail failed: '.$e->getMessage());
        }

        return true;
    }

    public function reset(string $email, string $token, string $password): bool
    {
        if (! $this->mailEnabled()) {
            return false;
        }
        $user = $this->users->findByLogin($email);
        if (! $user || ! $user->isActive()) {
            return false;
        }
        $status = Password::broker()->reset(
            ['email' => $user->email, 'token' => $token, 'password' => $password],
            fn ($u, $pw) => $this->users->update($u, ['password' => $pw])
        );

        return $status === Password::PASSWORD_RESET;
    }
}
