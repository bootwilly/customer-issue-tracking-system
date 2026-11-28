<?php

namespace App\Repositories;

use App\Models\IssueCloseTask;
use Illuminate\Support\Collection;

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
}
