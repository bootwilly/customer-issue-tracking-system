<?php

namespace App\Services;

use App\Models\Project;
use App\Models\User;
use App\Repositories\ProjectRepository;
use App\Repositories\SettingRepository;
use App\Repositories\UserRepository;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;

class AuthService
{
    public function __construct(
        private UserRepository $users,
        private ProjectRepository $projects,
        private SettingRepository $settings,
        private WorkflowService $wf,
    ) {}

    /**
     * Where a signed-in user lands: the first page their side and permissions open in the project.
     * Without a selected project the side default is returned; the project middleware picks the project first.
     */
    public function landingRoute(User $user, ?int $projectId): string
    {
        $pages = $user->seesInternal() && ! $user->isClient()
            ? [['pm.index', 'issue.view'], ['tickets.index', 'ticket.view']]
            : [['tickets.index', 'ticket.view'], ['pm.index', 'issue.view']];
        $pages = array_merge($pages, [
            ['meetings.index', 'meeting.view'], ['flows.index', 'flow.view'],
            ['padmin.index', 'project.members'], ['padmin.index', 'project.trash'],
        ]);
        if (! $projectId || ! $this->canUseProject($user, $projectId)) {
            return $pages[0][0];
        }
        foreach ($pages as [$route, $perm]) {
            $side = match ($route) {
                'pm.index' => $user->seesInternal(),
                'tickets.index' => $user->actsAsClient() || $user->seesInternal(),
                default => true,
            };
            if ($side && $this->wf->can($user, $perm, $projectId)) {
                return $route;
            }
        }

        return 'manual.show';
    }

    public function passwordMinLength(): int
    {
        return $this->settings->int('password.min_length', 6);
    }

    /** @return array{ok: bool, message: ?string} */
    public function attempt(string $login, string $password, string $ip, bool $remember = false): array
    {
        $key = mb_strtolower(trim($login)).'|'.$ip;
        $max = $this->settings->int('login.max_attempts', 5);
        $decaySeconds = $this->settings->int('login.decay_minutes', 15) * 60;

        if (RateLimiter::tooManyAttempts($key, $max)) {
            $minutes = (int) ceil(RateLimiter::availableIn($key) / 60);

            return ['ok' => false, 'message' => "登入嘗試次數過多，請於 {$minutes} 分鐘後再試。"];
        }

        $user = $this->users->findByLogin($login);

        if (! $user || ! Hash::check($password, $user->password)) {
            RateLimiter::hit($key, $decaySeconds);

            return ['ok' => false, 'message' => '帳號或密碼錯誤。'];
        }

        if (! $user->isActive()) {
            return ['ok' => false, 'message' => '此帳號已停用，請聯絡系統管理員。'];
        }

        RateLimiter::clear($key);
        Auth::login($user, $remember);
        $this->users->touchLogin($user);

        return ['ok' => true, 'message' => null];
    }

    /** @return Collection<int, Project> */
    public function projectsFor(User $user): Collection
    {
        return $user->is_admin ? $this->projects->active() : $this->projects->activeFor($user);
    }

    public function soleProjectId(User $user): ?int
    {
        $list = $this->projectsFor($user);

        return $list->count() === 1 ? $list->first()->id : null;
    }

    public function canUseProject(User $user, int $projectId): bool
    {
        return $this->projectsFor($user)->contains('id', $projectId);
    }

    /** @return array{ok: bool, message: ?string} */
    public function changePassword(User $user, string $current, string $new): array
    {
        if (! Hash::check($current, $user->password)) {
            return ['ok' => false, 'message' => '目前密碼不正確。'];
        }
        $this->users->update($user, ['password' => $new]);

        return ['ok' => true, 'message' => null];
    }
}
