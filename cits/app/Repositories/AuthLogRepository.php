<?php

namespace App\Repositories;

use App\Models\AuthLog;
use DateTimeInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class AuthLogRepository
{
    /** Column => max length (MariaDB sql_mode '' would truncate silently; cut here instead). */
    private const LIMITS = ['login' => 255, 'event' => 30, 'detail' => 30, 'ip' => 45, 'forwarded_for' => 255, 'user_agent' => 255];

    public function create(array $row): AuthLog
    {
        foreach (self::LIMITS as $col => $max) {
            if (isset($row[$col])) {
                $row[$col] = mb_substr((string) $row[$col], 0, $max);
            }
        }

        return AuthLog::create($row + ['created_at' => now()]);
    }

    /** @param array{user:?string,ip:?string,event:?string,from:?DateTimeInterface,to:?DateTimeInterface} $f */
    public function search(array $f, int $perPage): LengthAwarePaginator
    {
        return $this->query($f)->paginate($perPage)->withQueryString();
    }

    /** @return Collection<int, AuthLog> */
    public function export(array $f, int $limit): Collection
    {
        return $this->query($f)->limit($limit)->get();
    }

    /** @return Collection<int, AuthLog> */
    public function recentForUser(int $userId, int $limit): Collection
    {
        return AuthLog::where('user_id', $userId)->orderByDesc('id')->limit($limit)->get();
    }

    public function purgeBefore(DateTimeInterface $before): int
    {
        return AuthLog::where('created_at', '<', $before)->delete();
    }

    private function query(array $f): Builder
    {
        $q = AuthLog::with('user:id,account,name')->orderByDesc('id');
        if (! empty($f['user'])) {
            // '%' removed; '_' stays a one-character wildcard (only widens the match a little)
            $kw = '%'.str_replace('%', '', mb_strtolower($f['user'])).'%';
            $q->where(fn (Builder $w) => $w->whereRaw('lower(login) like ?', [$kw])
                ->orWhereHas('user', fn (Builder $u) => $u->whereRaw('lower(account) like ?', [$kw])->orWhereRaw('lower(name) like ?', [$kw])));
        }
        if (! empty($f['ip'])) {
            $q->where('ip', 'like', str_replace(['%', '_'], '', $f['ip']).'%');
        }
        if (! empty($f['event'])) {
            $q->where('event', $f['event']);
        }
        if (! empty($f['from'])) {
            $q->where('created_at', '>=', $f['from']);
        }
        if (! empty($f['to'])) {
            $q->where('created_at', '<=', $f['to']);
        }

        return $q;
    }
}
