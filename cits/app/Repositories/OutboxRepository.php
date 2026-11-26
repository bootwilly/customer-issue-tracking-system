<?php

namespace App\Repositories;

use App\Models\OutboxMail;
use Illuminate\Support\Collection;

class OutboxRepository
{
    /** @return Collection<int, OutboxMail> */
    public function pending(int $limit, int $maxAttempts): Collection
    {
        return OutboxMail::where('status', 'pending')->where('attempts', '<', $maxAttempts)->orderBy('id')->limit($limit)->get();
    }

    public function markSent(OutboxMail $m): void
    {
        $m->fill(['status' => 'sent', 'sent_at' => now(), 'attempts' => $m->attempts + 1, 'last_error' => null])->save();
    }

    public function markFailed(OutboxMail $m, string $error, int $maxAttempts): void
    {
        $attempts = $m->attempts + 1;
        $m->fill(['attempts' => $attempts, 'last_error' => mb_substr($error, 0, 500), 'status' => $attempts >= $maxAttempts ? 'failed' : 'pending'])->save();
    }
}
