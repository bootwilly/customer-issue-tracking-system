<?php

namespace App\Repositories;

use App\Models\EditorDraft;

class DraftRepository
{
    public function find(int $userId, string $context): ?EditorDraft
    {
        return EditorDraft::query()->where('user_id', $userId)->where('context_key', $context)->first();
    }

    public function save(int $userId, string $context, string $html): void
    {
        EditorDraft::query()->updateOrInsert(
            ['user_id' => $userId, 'context_key' => $context],
            ['content_html' => $html, 'updated_at' => now()],
        );
    }

    public function delete(int $userId, string $context): void
    {
        EditorDraft::query()->where('user_id', $userId)->where('context_key', $context)->delete();
    }

    public function countFor(int $userId): int
    {
        return EditorDraft::query()->where('user_id', $userId)->count();
    }

    public function deleteOldestFor(int $userId, int $n): void
    {
        $ids = EditorDraft::query()->where('user_id', $userId)->orderBy('updated_at')->limit(max(0, $n))->pluck('context_key');
        EditorDraft::query()->where('user_id', $userId)->whereIn('context_key', $ids)->delete();
    }

    public function deleteOlderThan(\DateTimeInterface $cutoff): int
    {
        return EditorDraft::query()->where('updated_at', '<', $cutoff)->delete();
    }
}
