<?php

namespace App\Repositories;

use App\Models\Issue;
use App\Models\IssueDependency;
use Illuminate\Support\Collection;

class DependencyRepository
{
    public function find(int $id): ?IssueDependency
    {
        return IssueDependency::find($id);
    }

    public function exists(int $issueId, int $dependsOnId): bool
    {
        return IssueDependency::where(['issue_id' => $issueId, 'depends_on_id' => $dependsOnId])
            ->orWhere(fn ($q) => $q->where(['issue_id' => $dependsOnId, 'depends_on_id' => $issueId]))->exists();
    }

    public function create(array $data): IssueDependency
    {
        return IssueDependency::create($data);
    }

    public function delete(IssueDependency $d): void
    {
        $d->delete();
    }

    /** @return Collection<int, IssueDependency> what this issue waits for (with the upstream issue) */
    public function upstream(int $issueId): Collection
    {
        return IssueDependency::with('dependsOn:id,project_id,issue_no,title,status')->where('issue_id', $issueId)->whereHas('dependsOn')->orderBy('id')->get();
    }

    /** @return Collection<int, IssueDependency> issues waiting for this one */
    public function downstream(int $issueId): Collection
    {
        return IssueDependency::with('issue:id,project_id,issue_no,title,status')->where('depends_on_id', $issueId)->whereHas('issue')->orderBy('id')->get();
    }

    /**
     * @param  string[]  $kinds
     * @return int[] issues reachable upstream from $issueId over $kinds edges (breadth-first, at most $limit nodes)
     */
    public function reachableUpstream(int $issueId, array $kinds, int $limit): array
    {
        $seen = [];
        $frontier = [$issueId];
        while ($frontier && count($seen) < $limit) {
            $next = IssueDependency::whereIn('issue_id', $frontier)->whereIn('kind', $kinds)->pluck('depends_on_id')->all();
            $frontier = array_values(array_diff(array_unique($next), array_keys($seen), [$issueId]));
            foreach ($frontier as $id) {
                $seen[$id] = true;
            }
        }

        return array_keys($seen);
    }

    /**
     * @param  int[]  $issueIds
     * @param  string[]  $kinds
     * @param  string[]  $doneStatuses  upstream statuses that no longer block
     * @return array<int, string[]> issue id => upstream issue numbers still blocking it
     */
    public function blockers(array $issueIds, array $kinds, array $doneStatuses): array
    {
        if (! $issueIds) {
            return [];
        }
        $out = [];
        IssueDependency::query()->join('issues as up', 'up.id', '=', 'issue_dependencies.depends_on_id')
            ->whereIn('issue_dependencies.issue_id', $issueIds)->whereIn('issue_dependencies.kind', $kinds)
            ->whereNotIn('up.status', $doneStatuses ?: ['-'])->whereNull('up.deleted_at')
            ->orderBy('up.issue_no')
            ->get(['issue_dependencies.issue_id', 'up.issue_no'])
            ->each(function ($r) use (&$out) {
                $out[(int) $r->issue_id][] = $r->issue_no;
            });

        return $out;
    }

    public function issueByNo(int $projectId, string $no): ?Issue
    {
        return Issue::where('project_id', $projectId)->where('issue_no', $no)->first();
    }
}
