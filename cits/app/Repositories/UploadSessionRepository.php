<?php

namespace App\Repositories;

use App\Models\UploadSession;
use Illuminate\Database\Eloquent\Collection;

class UploadSessionRepository
{
    public function create(array $data): UploadSession
    {
        return UploadSession::query()->create($data);
    }

    public function find(string $id): ?UploadSession
    {
        return UploadSession::query()->find($id);
    }

    public function setReceived(UploadSession $s, int $count): void
    {
        $s->update(['received_chunks' => $count]);
    }

    /** Atomically move a session from one status to another; false when another request got there first. */
    public function claim(UploadSession $s, string $from, string $to): bool
    {
        return UploadSession::whereKey($s->id)->where('status', $from)->update(['status' => $to]) === 1;
    }

    public function setStatus(UploadSession $s, string $status): void
    {
        $s->update(['status' => $status]);
    }

    /** @return Collection<int, UploadSession> */
    public function olderThan(\DateTimeInterface $cutoff): Collection
    {
        return UploadSession::query()->where('created_at', '<', $cutoff)->get();
    }

    public function delete(UploadSession $s): void
    {
        $s->delete();
    }
}
