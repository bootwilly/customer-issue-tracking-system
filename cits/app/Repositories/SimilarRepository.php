<?php

namespace App\Repositories;

use App\Models\ClientTicket;
use App\Models\Issue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/** Candidate rows for the duplicate hint: title LIKE any token (no FULLTEXT / ngram on the host). */
class SimilarRepository
{
    /** @param string[] $tokens @param string[] $excludeStatuses @return Collection<int, ClientTicket> */
    public function tickets(int $projectId, array $tokens, array $excludeStatuses, int $limit): Collection
    {
        return $this->likeAny(ClientTicket::query()->where('project_id', $projectId), $tokens)
            ->when($excludeStatuses, fn ($q, $v) => $q->whereNotIn('status', $v))
            ->orderByDesc('id')->limit($limit)->get(['id', 'ticket_no', 'title', 'status', 'created_at']);
    }

    /** @param string[] $tokens @param string[] $excludeStatuses @return Collection<int, Issue> */
    public function issues(int $projectId, array $tokens, array $excludeStatuses, int $limit): Collection
    {
        return $this->likeAny(Issue::query()->where('project_id', $projectId), $tokens)
            ->when($excludeStatuses, fn ($q, $v) => $q->whereNotIn('status', $v))
            ->orderByDesc('id')->limit($limit)->get(['id', 'issue_no', 'title', 'status', 'created_at']);
    }

    private function likeAny(Builder $q, array $tokens): Builder
    {
        return $q->where(function ($w) use ($tokens) {
            foreach ($tokens as $t) {
                $w->orWhere('title', 'like', '%'.addcslashes($t, '%_\\').'%');
            }
        });
    }
}
