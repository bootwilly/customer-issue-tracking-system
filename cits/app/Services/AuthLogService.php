<?php

namespace App\Services;

use App\Models\User;
use App\Repositories\AuthLogRepository;
use App\Repositories\SettingRepository;
use App\Repositories\UserRepository;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Sign-in audit trail (table auth_logs). Event and detail codes are the lookup categories
 * auth_log_event / auth_log_detail; the constants below are the codes the code paths write.
 * Writing a log row never breaks the action being logged.
 */
class AuthLogService
{
    public const LOGIN_OK = 'login_ok';

    public const LOGIN_FAIL = 'login_fail';

    public const LOGIN_LOCKED = 'login_locked';

    public const LOGOUT = 'logout';

    public const IDLE_LOGOUT = 'idle_logout';

    public const DISABLED_KICK = 'disabled_kick';

    public const PASSWORD_CHANGED = 'password_changed';

    public const PASSWORD_RESET_REQUESTED = 'password_reset_requested';

    public const PASSWORD_RESET = 'password_reset';

    private const TZ = 'Asia/Taipei';

    private const PER_PAGE = 50;

    public function __construct(
        private AuthLogRepository $logs,
        private UserRepository $users,
        private SettingRepository $settings,
        private WorkflowService $wf,
    ) {}

    /** $user null + $login given: the user is looked up by account/email (unknown logins stay null). */
    public function record(Request $request, string $event, ?User $user = null, ?string $login = null, ?string $detail = null): void
    {
        try {
            $login = $login !== null ? trim($login) : $user?->account;
            $user ??= $login ? $this->users->findByLogin($login) : null;
            $this->logs->create([
                'user_id' => $user?->id,
                'login' => $login,
                'event' => $event,
                'detail' => $detail,
                'ip' => $request->ip(),
                'forwarded_for' => $request->headers->get('X-Forwarded-For'),
                'user_agent' => $request->userAgent(),
            ]);
        } catch (Throwable $e) {
            Log::warning('auth log write failed: '.$e->getMessage());
        }
    }

    /** @return array<string,string> code => label */
    public function events(): array
    {
        return $this->wf->lookupMapAll('auth_log_event');
    }

    /** @return array<string,string> code => label */
    public function details(): array
    {
        return $this->wf->lookupMapAll('auth_log_detail');
    }

    public function eventLabel(?string $code): string
    {
        return $this->wf->lookupLabel('auth_log_event', $code);
    }

    public function detailLabel(?string $code): string
    {
        return $this->wf->lookupLabel('auth_log_detail', $code);
    }

    /** Sanitised filters from the query string (dates are Asia/Taipei Y-m-d). */
    public function filters(array $in): array
    {
        $date = function ($v) {
            $v = is_string($v) ? trim($v) : '';
            $d = $v !== '' ? \DateTimeImmutable::createFromFormat('!Y-m-d', $v) : false;

            return $d && $d->format('Y-m-d') === $v ? $v : null;
        };
        $text = fn ($v, int $max) => is_string($v) && trim($v) !== '' ? mb_substr(trim($v), 0, $max) : null;
        $event = (string) ($in['event'] ?? '');

        return [
            'user' => $text($in['user'] ?? null, 100),
            'ip' => $text($in['ip'] ?? null, 45),
            'event' => array_key_exists($event, $this->events()) ? $event : null,
            'from' => $date($in['from'] ?? null),
            'to' => $date($in['to'] ?? null),
        ];
    }

    public function search(array $f): LengthAwarePaginator
    {
        return $this->logs->search($this->range($f), self::PER_PAGE);
    }

    /** @return Collection<int, \App\Models\AuthLog> */
    public function exportRows(array $f, int $limit): Collection
    {
        return $this->logs->export($this->range($f), $limit);
    }

    /** @return Collection<int, \App\Models\AuthLog> */
    public function recentForUser(User $user, int $limit = 10): Collection
    {
        return $this->logs->recentForUser($user->id, $limit);
    }

    /** Days to keep (system setting auth_log.keep_days, 0 = keep forever). */
    public function keepDays(): int
    {
        return max(0, $this->settings->int('auth_log.keep_days', 180));
    }

    public function purge(): int
    {
        $days = $this->keepDays();

        return $days > 0 ? $this->logs->purgeBefore(now()->subDays($days)) : 0;
    }

    private function range(array $f): array
    {
        return [
            'from' => ! empty($f['from']) ? CarbonImmutable::parse($f['from'], self::TZ)->startOfDay()->utc() : null,
            'to' => ! empty($f['to']) ? CarbonImmutable::parse($f['to'], self::TZ)->endOfDay()->utc() : null,
        ] + $f;
    }
}
