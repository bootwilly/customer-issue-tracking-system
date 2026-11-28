<?php

namespace App\Repositories;

use App\Models\IssueCloseTask;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class IssueCloseRepository
{
    public function createTasks(int $issueId, int $round, array $rows): void
    {
        foreach ($rows as $row) {
            IssueCloseTask::create(['issue_id' => $issueId, 'round' => $round, 'status' => 'pending'] + $row);
        }
    }

    public function maxRound(int $issueId): int
    {
        return (int) IssueCloseTask::where('issue_id', $issueId)->orderByDesc('round')->lockForUpdate()->value('round');
    }

    public function pending(int $issueId, int $round): Collection
    {
        return IssueCloseTask::where(['issue_id' => $issueId, 'round' => $round, 'status' => 'pending'])->lockForUpdate()->get();
    }

    public function issueIdOfTask(int $taskId): ?int
    {
        return IssueCloseTask::whereKey($taskId)->value('issue_id');
    }

    public function lockTask(int $taskId): ?IssueCloseTask
    {
        return IssueCloseTask::whereKey($taskId)->lockForUpdate()->first();
    }

    public function markDone(IssueCloseTask $task, int $actorId, string $note): void
    {
        $task->update(['status' => 'done', 'note' => $note, 'done_at' => now(), 'done_by' => $actorId]);
    }

    public function voidRound(int $issueId, int $round): void
    {
        IssueCloseTask::where(['issue_id' => $issueId, 'round' => $round, 'status' => 'pending'])->update(['status' => 'void']);
    }

    public function pendingForUser(int $projectId, int $userId, bool $release): Collection
    {
        return IssueCloseTask::with('issue:id,issue_no,title,close_reason')->join('issues', 'issues.id', '=', 'issue_close_tasks.issue_id')
            ->where('issues.project_id', $projectId)->whereNull('issues.deleted_at')->whereNotNull('issues.close_target')
            ->where('issue_close_tasks.status', 'pending')->where(function ($q) use ($userId, $release) {
                $q->where('user_id', $userId);
                if ($release) {
                    $q->orWhere(fn ($r) => $r->whereNull('user_id')->where('role', 'release'));
                }
            })->orderBy('issue_close_tasks.id')->get(['issue_close_tasks.*']);
    }

    public function ofIssue(int $issueId): Collection
    {
        return IssueCloseTask::with(['user:id,name', 'doneBy:id,name'])->where('issue_id', $issueId)
            ->orderByDesc('round')->orderBy('id')->get();
    }

    /** Group before filtering by owner so progress includes every task in the round. */
    public function rounds(int $projectId, ?string $status, string $keyword, ?int $mine, bool $admin, bool $release, ?int $limit, string $pageName): Collection|LengthAwarePaginator
    {
        $totals = DB::table('issue_close_tasks as t')
            ->join('issues as i', 'i.id', '=', 't.issue_id')
            ->where('i.project_id', $projectId)->whereNull('i.deleted_at')
            ->when($keyword !== '', fn ($q) => $q->where(fn ($w) => $w
                ->where('i.issue_no', 'like', "%{$keyword}%")->orWhere('i.title', 'like', "%{$keyword}%")))
            ->groupBy('t.issue_id', 't.round', 'i.issue_no', 'i.title', 'i.created_at')
            ->select('t.issue_id', 't.round', 'i.issue_no', 'i.title', 'i.created_at')
            ->selectRaw("COUNT(*) AS total, COUNT(CASE WHEN t.status = 'done' THEN 1 END) AS done_count")
            ->selectRaw("CASE WHEN COUNT(CASE WHEN t.status = 'void' THEN 1 END) > 0 THEN 'void'
                WHEN COUNT(CASE WHEN t.status = 'pending' THEN 1 END) > 0 THEN 'pending' ELSE 'done' END AS round_status");

        $query = DB::query()->fromSub($totals, 'r')
            ->join('events as e', fn ($j) => $j->on('e.subject_id', '=', 'r.issue_id')
                ->on('e.payload->round', '=', 'r.round')->where('e.project_id', $projectId)
                ->where('e.subject_type', 'issue')->where('e.event_type', 'close_requested'))
            ->select('r.*', 'e.id as request_event_id', 'e.payload->target as target', 'e.created_at as requested_at')
            ->when($status !== null, fn ($q) => $q->where('r.round_status', $status))
            ->orderBy('e.created_at')->orderBy('e.id');
        if ($mine !== null) {
            $query->whereExists(fn ($q) => $q->selectRaw('1')->from('issue_close_tasks as own')
                ->whereColumn('own.issue_id', 'r.issue_id')->whereColumn('own.round', 'r.round')
                ->where('own.status', 'pending')->where(function ($w) use ($mine, $admin, $release) {
                    $admin ? $w->whereNotNull('own.user_id') : $w->where('own.user_id', $mine);
                    if ($release) {
                        $w->orWhere(fn ($pool) => $pool->whereNull('own.user_id')->where('own.role', 'release'));
                    }
                }));
        }

        return $limit === null ? $query->paginate(20, ['*'], $pageName)->withQueryString() : $query->limit($limit)->get();
    }

    public function pendingOwners(array $issueIds): Collection
    {
        return DB::table('issue_close_tasks as t')->leftJoin('users as u', 'u.id', '=', 't.user_id')
            ->whereIntegerInRaw('t.issue_id', $issueIds)->where('t.status', 'pending')
            ->orderBy('t.id')->get(['t.issue_id', 't.round', 't.role', 'u.name']);
    }
}
