<?php

namespace App\Repositories;

use App\Models\UserNotification;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class NotificationRepository
{
    public function unreadCount(int $userId): int
    {
        return UserNotification::where('user_id', $userId)->whereNull('read_at')->count();
    }

    public function paginate(int $userId, bool $unreadOnly, int $perPage = 30): LengthAwarePaginator
    {
        return UserNotification::where('user_id', $userId)
            ->when($unreadOnly, fn ($q) => $q->whereNull('read_at'))
            ->orderByDesc('id')
            ->paginate($perPage)
            ->withQueryString();
    }

    public function findOwned(int $userId, int $id): ?UserNotification
    {
        return UserNotification::where('user_id', $userId)->where('id', $id)->first();
    }

    public function markRead(UserNotification $n): void
    {
        if ($n->read_at === null) {
            $n->forceFill(['read_at' => now()])->save();
        }
    }

    public function markAllRead(int $userId): int
    {
        return UserNotification::where('user_id', $userId)->whereNull('read_at')->update(['read_at' => now()]);
    }
}
