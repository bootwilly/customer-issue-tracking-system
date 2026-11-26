<?php

namespace App\Services;

use App\Models\User;
use App\Models\UserNotification;
use App\Repositories\NotificationRepository;
use App\Repositories\UserRepository;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class NotificationService
{
    public function __construct(private NotificationRepository $repo, private UserRepository $users) {}

    public function unreadCount(User $user): int
    {
        return $this->repo->unreadCount($user->id);
    }

    public function list(User $user, bool $unreadOnly): LengthAwarePaginator
    {
        return $this->repo->paginate($user->id, $unreadOnly, 30);
    }

    /** Marks an own notification read; null when it does not belong to the user. */
    public function markRead(User $user, int $id): ?UserNotification
    {
        $n = $this->repo->findOwned($user->id, $id);
        if ($n) {
            $this->repo->markRead($n);
        }

        return $n;
    }

    public function markAllRead(User $user): int
    {
        return $this->repo->markAllRead($user->id);
    }

    public function setEmailOptIn(User $user, bool $on): void
    {
        $this->users->update($user, ['email_notify' => $on]);
    }
}
