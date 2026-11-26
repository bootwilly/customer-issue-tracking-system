<?php

namespace App\Services\Collab;

use App\Exceptions\BusinessException;
use App\Models\Issue;
use App\Models\IssueDependency;
use App\Models\User;
use App\Models\WorkflowStatus;
use App\Repositories\DependencyRepository;
use App\Repositories\EventRepository;
use App\Repositories\UnitOfWork;
use App\Services\WorkflowService;
use Illuminate\Support\Collection;

/**
 * Issue dependencies. Kinds come from lookup dependency_kind; kinds in dependency_blocking stop the issue from being
 * dispatched from the PM stage to DEV until every blocking upstream is released or excluded (is_released / is_excluded).
 * Settlement ignores dependencies on purpose (a ticket never waits on an upstream issue it is not linked to).
 */
class DependencyService
{
    /** Cycle search bound (nodes). */
    private const GRAPH_LIMIT = 200;

    public function __construct(
        private DependencyRepository $repo,
        private EventRepository $events,
        private WorkflowService $wf,
        private UnitOfWork $uow,
    ) {}

    public function canManage(User $u, Issue $i): bool
    {
        return $u->seesInternal() && $this->wf->can($u, 'issue.link', $i->project_id);
    }

    /** @return array<string,string> kind code => label */
    public function kinds(): array
    {
        return $this->wf->lookupMap('dependency_kind');
    }

    /** @return array{upstream: Collection<int, IssueDependency>, downstream: Collection<int, IssueDependency>, blockers: string[]} */
    public function forIssue(Issue $i): array
    {
        return [
            'upstream' => $this->repo->upstream($i->id),
            'downstream' => $this->repo->downstream($i->id),
            'blockers' => $this->blockers([$i->id])[$i->id] ?? [],
        ];
    }

    /** @param int[] $issueIds @return array<int, string[]> issue id => upstream numbers still blocking */
    public function blockers(array $issueIds): array
    {
        return $this->repo->blockers($issueIds, $this->wf->lookupCodes('dependency_blocking'), $this->doneStatuses());
    }

    public function add(User $u, Issue $i, string $upstreamNo, string $kind): IssueDependency
    {
        if (! $this->canManage($u, $i)) {
            throw new BusinessException('沒有權限執行此操作。');
        }
        if (! array_key_exists($kind, $this->kinds())) {
            throw new BusinessException('相依類型不正確。');
        }

        return $this->uow->run(function () use ($u, $i, $upstreamNo, $kind) {
            $up = $this->repo->issueByNo($i->project_id, trim($upstreamNo)) ?? throw new BusinessException("找不到內部單「{$upstreamNo}」。");
            if ($up->id === $i->id) {
                throw new BusinessException('不能相依自己。');
            }
            if ($this->repo->exists($i->id, $up->id)) {
                throw new BusinessException('這兩張單已經有相依關係。');
            }
            $blocking = in_array($kind, $this->wf->lookupCodes('dependency_blocking'), true);
            if ($blocking && in_array($i->origin, $this->wf->lookupCodes('dependency_free_origin'), true)) {
                throw new BusinessException('Test 新發現的單直接進 DEV，不能設定為需等待其他單。');
            }
            if ($blocking && in_array($i->id, $this->repo->reachableUpstream($up->id, $this->wf->lookupCodes('dependency_blocking'), self::GRAPH_LIMIT), true)) {
                throw new BusinessException("{$up->issue_no} 已經（間接）在等待 {$i->issue_no}，不能形成循環。");
            }
            $d = $this->repo->create(['issue_id' => $i->id, 'depends_on_id' => $up->id, 'kind' => $kind, 'created_by' => $u->id, 'created_at' => now()]);
            $payload = ['kind' => $kind];
            $this->events->log($i->project_id, 'issue', $i->id, 'dependency_added', null, null, $u->id, $payload + ['issue' => $up->issue_no]);
            $this->events->log($i->project_id, 'issue', $up->id, 'dependency_added', null, null, $u->id, $payload + ['issue' => $i->issue_no]);

            return $d;
        });
    }

    public function remove(User $u, Issue $i, int $dependencyId): void
    {
        if (! $this->canManage($u, $i)) {
            throw new BusinessException('沒有權限執行此操作。');
        }
        $this->uow->run(function () use ($u, $i, $dependencyId) {
            $d = $this->repo->find($dependencyId);
            if (! $d || ($d->issue_id !== $i->id && $d->depends_on_id !== $i->id)) {
                throw new BusinessException('找不到這個相依關係。');
            }
            $other = $d->issue_id === $i->id ? $d->dependsOn : $d->issue;
            $this->repo->delete($d);
            $this->events->log($i->project_id, 'issue', $i->id, 'dependency_removed', null, null, $u->id, ['issue' => $other?->issue_no, 'kind' => $d->kind]);
            if ($other) {
                $this->events->log($i->project_id, 'issue', $other->id, 'dependency_removed', null, null, $u->id, ['issue' => $i->issue_no, 'kind' => $d->kind]);
            }
        });
    }

    /** Dispatch rule: leaving the PM stage into DEV needs every blocking upstream done. Read-only, inside the caller's transaction. */
    public function assertCanEnter(Issue $i, WorkflowStatus $from, WorkflowStatus $to): void
    {
        $pm = $this->wf->status('issue', $this->wf->initial('issue_initial', 'pm'))->stage;
        $dev = $this->wf->lookupValue('stage_role', 'dev');
        if ($from->stage !== $pm || $to->stage !== $dev) {
            return;
        }
        $left = $this->blockers([$i->id])[$i->id] ?? [];
        if ($left) {
            throw new BusinessException('需等待 '.implode('、', $left).' 上線或排除後才能派給 DEV。');
        }
    }

    /** @return string[] issue statuses that no longer block (released or excluded) */
    private function doneStatuses(): array
    {
        return $this->wf->statusMap('issue')->filter(fn ($s) => $s->is_released || $s->is_excluded)->keys()->all();
    }
}
