<?php

namespace App\Repositories;

use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** Watchers of client tickets and internal issues (subject = ticket | issue). */
class WatchRepository
{
    private const TABLES = ['ticket' => ['ticket_watchers', 'ticket_id'], 'issue' => ['issue_watchers', 'issue_id']];

    public function add(string $subject, int $id, int $userId): void
    {
        [$table, $col] = self::TABLES[$subject];
        DB::table($table)->insertOrIgnore([$col => $id, 'user_id' => $userId, 'created_at' => now()]);
    }

    public function remove(string $subject, int $id, int $userId): void
    {
        [$table, $col] = self::TABLES[$subject];
        DB::table($table)->where([$col => $id, 'user_id' => $userId])->delete();
    }

    public function has(string $subject, int $id, int $userId): bool
    {
        [$table, $col] = self::TABLES[$subject];

        return DB::table($table)->where([$col => $id, 'user_id' => $userId])->exists();
    }

    public function removeAllFor(string $subject, int $userId): void
    {
        [$table] = self::TABLES[$subject];
        DB::table($table)->where('user_id', $userId)->delete();
    }

    /** @return Collection<int, User> active watchers, oldest subscription first */
    public function watchers(string $subject, int $id): Collection
    {
        [$table, $col] = self::TABLES[$subject];

        return User::query()->join($table.' as w', 'w.user_id', '=', 'users.id')
            ->where("w.$col", $id)->where('users.status', 'active')
            ->orderBy('w.created_at')->orderBy('users.id')
            ->get(['users.id', 'users.name', 'users.user_type', 'users.is_admin']);
    }
}
