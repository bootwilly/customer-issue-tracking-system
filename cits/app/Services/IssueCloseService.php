<?php

namespace App\Services;

use App\Exceptions\BusinessException;
use App\Models\Issue;
use App\Models\IssueCloseTask;
use App\Models\User;
use App\Repositories\EventRepository;
use App\Repositories\IssueCloseRepository;
use App\Repositories\IssueRepository;
use App\Repositories\TicketRepository;
use App\Repositories\UnitOfWork;
use App\Services\Collab\WatchService;
use Illuminate\Support\Collection;

class IssueCloseService
{
    public function __construct(
        private IssueRepository $issues,
        private IssueCloseRepository $tasks,
        private TicketRepository $tickets,
        private EventRepository $events,
        private WorkflowService $wf,
        private WatchService $watch,
        private SettlementService $settlement,
        private IssueService $issueService,
        private UnitOfWork $uow,
    ) {}

    public function resolved(string $status): bool
    {
        return in_array($status, $this->wf->lookupCodes('issue_resolved'), true);
    }

    public function meetingTarget(): string
    {
        return $this->wf->lookupValue('issue_close_state', 'meeting');
    }

    public function mayRequest(User $user, int $pid): bool
    {
        return $user->seesInternal() && $this->wf->can($user, 'issue.close', $pid);
    }

    private function wrapup(Issue $issue): array
    {
        $rule = $this->wf->lookupMap('issue_close_wrapup')[$issue->status] ?? '';
        $rows = [];
        foreach (array_filter(explode(',', $rule)) as $role) {
            $userId = match ($role) {
                'dev' => $issue->assignee_dev_id,
                'test' => $issue->assignee_test_id,
                'release' => null,
                default => throw new BusinessException("未定義的收尾角色：$role"),
            };
            if ($userId !== null || $role === 'release') {
                $rows[] = ['role' => $role, 'user_id' => $userId];
            }
        }

        return $rows;
    }

    public function preview(Issue $issue): string
    {
        $names = [];
        foreach ($this->wrapup($issue) as ['role' => $role]) {
            $name = match ($role) {
                'dev' => $issue->dev?->name,
                'test' => $issue->tester?->name,
                default => null,
            };
            $names[] = trim($this->wf->lookupLabel('issue_close_role', $role).' '.$name);
        }

        return $names ? '送出後需要以下人員收尾：'.implode('、', $names) : '沒有進行中的工作，送出後直接結案';
    }

    public function page(Issue $issue, User $user): array
    {
        $history = $this->tasks->ofIssue($issue->id);

        return [
            'closePreview' => $this->preview($issue),
            'closeTasks' => $history,
            'closeRoleLabels' => $this->wf->lookupMapAll('issue_close_role'),
            'closeTaskLabels' => $this->wf->lookupMapAll('issue_close_task_status'),
            'ownCloseTasks' => $history->filter(fn ($t) => $t->status === 'pending' && $this->canFinish($user, $issue->project_id, $t))->pluck('id')->all(),
            'closeLabel' => $this->wf->label('issue', $issue->close_target ?? $issue->status),
            'canWithdraw' => $issue->close_target !== null && $this->mayRequest($user, $issue->project_id),
        ];
    }

    public function pendingFor(User $user, int $pid): Collection
    {
        return $this->tasks->pendingForUser($pid, $user->id, $this->wf->can($user, 'release.manage', $pid));
    }

    public function request(User $u, int $pid, int $issueId, string $target, string $reason, ?string $clientNote, ?int $meetingItemId = null): Issue
    {
        return $this->uow->run(function () use ($u, $pid, $issueId, $target, $reason, $clientNote, $meetingItemId) {
            $issue = $this->locked($u, $pid, $issueId);
            $from = $issue->status;
            if (! $this->mayRequest($u, $pid) || ! $this->resolved($target) || ($target === $this->meetingTarget()) !== ($meetingItemId !== null)) {
                throw new BusinessException('沒有權限執行此結案方式。');
            }
            if ($issue->close_target !== null) {
                throw new BusinessException('此內部單已在結案收尾中');
            }
            $reason = trim($reason);
            $note = trim((string) $clientNote);
            if ($reason === '' || mb_strlen($reason) > 1000 || mb_strlen($note) > 500) {
                throw new BusinessException('結案原因必填且最多 1000 字，給客戶的說明最多 500 字。');
            }
            if ($note === '' && $this->tickets->ticketsOfIssue($issueId, true)->isNotEmpty()) {
                throw new BusinessException('此內部單有連結客戶單，請填寫給客戶看的說明。');
            }
            $actor = $meetingItemId === null ? $u : null;
            $this->wf->assertTransition('issue', $from, $target, $actor, $pid, ['close_reason' => $reason]);
            $this->issues->save($issue, ['close_reason' => $reason, 'closed_by' => $u->id, 'close_meeting_item_id' => $meetingItemId, 'client_note' => $note === '' ? null : $note]);
            if ($this->issues->rejectPendingDue($issueId, $u->id)) {
                $this->events->log($pid, 'issue', $issueId, 'due_review', null, null, $u->id, ['note' => '自動駁回：內部單結案']);
            }
            $rows = $this->wrapup($issue);
            if (! $rows) {
                return $this->complete($u, $issue, $target);
            }
            $closing = $this->wf->lookupValue('issue_close_state', 'wrapup');
            $round = $this->tasks->maxRound($issueId) + 1;
            $this->issues->save($issue, ['status' => $closing, 'stage' => $this->wf->status('issue', $closing)->stage, 'close_target' => $target, 'close_from' => $from]);
            $this->tasks->createTasks($issueId, $round, $rows);
            $roles = array_map(fn ($r) => $this->wf->lookupLabel('issue_close_role', $r['role']), $rows);
            $this->events->log($pid, 'issue', $issueId, 'close_requested', $from, $closing, $u->id, compact('round', 'target', 'from', 'reason', 'roles'));
            $this->notifyOwners($u, $issue, collect($rows), '已送出結案，請完成收尾');
            $this->settlement->recompute($issueId, $u->id);

            return $issue;
        });
    }

    private function locked(User $u, int $pid, int $issueId): Issue
    {
        $issue = $this->issues->findForUpdate($issueId);
        if (! $issue || $issue->project_id !== $pid || ! $u->seesInternal()) {
            throw new BusinessException('找不到這張內部單。');
        }

        return $issue;
    }

    private function canFinish(User $u, int $pid, IssueCloseTask $task): bool
    {
        return $task->user_id !== null ? ($u->id === $task->user_id || $u->is_admin)
            : ($task->role === 'release' && $this->wf->can($u, 'release.manage', $pid));
    }

    public function finishTask(User $u, int $pid, int $taskId, string $note, ?int $issueId = null): Issue
    {
        return $this->uow->run(function () use ($u, $pid, $issueId, $taskId, $note) {
            $issueId ??= $this->tasks->issueIdOfTask($taskId) ?? throw new BusinessException('找不到收尾工作。');
            $issue = $this->locked($u, $pid, $issueId);
            $task = $this->tasks->lockTask($taskId);
            if (! $task || $task->issue_id !== $issueId || $issue->status !== $this->wf->lookupValue('issue_close_state', 'wrapup') || $issue->close_target === null || $task->status !== 'pending' ||
                $task->round !== $this->tasks->maxRound($issueId) || ! $this->canFinish($u, $pid, $task)) {
                throw new BusinessException('目前無法確認這筆收尾工作。');
            }
            $note = trim($note);
            if ($note === '' || mb_strlen($note) > 1000) {
                throw new BusinessException('收尾說明必填且最多 1000 字。');
            }
            $this->tasks->markDone($task, $u->id, $note);
            $this->events->log($pid, 'issue', $issueId, 'close_task_done', null, null, $u->id, ['role' => $task->role, 'round' => $task->round, 'note' => $note]);
            $this->watch->notifyIssue($issue, "{$issue->issue_no} 已完成一筆收尾", $u->id, [$issue->closed_by]);

            return $this->tasks->pending($issueId, $task->round)->isEmpty() ? $this->complete($u, $issue, $issue->close_target) : $issue;
        });
    }

    public function withdraw(User $u, int $pid, int $issueId): Issue
    {
        return $this->uow->run(function () use ($u, $pid, $issueId) {
            $issue = $this->locked($u, $pid, $issueId);
            $this->wf->authorize($u, 'issue.close', $pid);
            $back = $issue->close_from;
            if (! $back || $issue->status !== $this->wf->lookupValue('issue_close_state', 'wrapup') || $issue->close_target === null) {
                throw new BusinessException('此單目前沒有進行中的結案。');
            }
            $from = $issue->status;
            $this->wf->assertTransition('issue', $from, $back, null, $pid);
            $round = $this->tasks->maxRound($issueId);
            $owners = $this->tasks->ofIssue($issueId)->where('round', $round);
            $this->tasks->voidRound($issueId, $round);
            $this->issues->save($issue, ['status' => $back, 'stage' => $this->wf->status('issue', $back)->stage, 'close_target' => null, 'close_from' => null,
                'close_meeting_item_id' => null, 'close_reason' => null, 'closed_by' => null, 'client_note' => null]);
            $this->events->log($pid, 'issue', $issueId, 'close_withdrawn', $from, $back, $u->id, ['round' => $round]);
            $this->notifyOwners($u, $issue, $owners, '結案已撤回，請繼續處理');
            $this->settlement->recompute($issueId, $u->id);

            return $issue;
        });
    }

    private function notifyOwners(User $actor, Issue $issue, Collection $rows, string $message): void
    {
        $ids = $rows->pluck('user_id')->filter()->all();
        if ($rows->contains('role', 'release')) {
            $ids = array_merge($ids, $this->events->userIdsWithPermission($issue->project_id, 'release.manage'));
        }
        $this->watch->notifyIssue($issue, "{$issue->issue_no} {$message}", $actor->id, $ids);
    }

    private function complete(User $u, Issue $issue, string $target): Issue
    {
        $from = $issue->status;
        $wrapped = $issue->close_target !== null;
        if ($wrapped) {
            $this->wf->assertTransition('issue', $from, $target, null, $issue->project_id);
        }
        $this->issueService->detachOpenPackage($issue);
        $this->issues->save($issue, ['status' => $target, 'stage' => $this->wf->status('issue', $target)->stage, 'closed_at' => now(), 'close_target' => null, 'close_from' => null]);
        $this->events->log($issue->project_id, 'issue', $issue->id, 'status', $from, $target, $u->id);
        if ($wrapped) {
            $this->events->log($issue->project_id, 'issue', $issue->id, 'close_completed', $from, $target, $u->id);
        }
        $this->watch->notifyIssue($issue, "{$issue->issue_no} 已結案", $u->id, [$issue->assignee_dev_id, $issue->assignee_test_id, $issue->closed_by]);
        $this->settlement->recompute($issue->id, $u->id);

        return $issue;
    }
}
