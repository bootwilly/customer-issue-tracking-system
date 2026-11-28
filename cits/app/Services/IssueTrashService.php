<?php

namespace App\Services;

use App\Exceptions\BusinessException;
use App\Models\Issue;
use App\Models\User;
use App\Repositories\EventRepository;
use App\Repositories\IssueRepository;
use App\Repositories\MeetingRepository;
use App\Repositories\UnitOfWork;
use Illuminate\Support\Collection;

/**
 * Recycle bin of internal issues. Only issues in an issue_deletable status (新建) can be deleted; a deleted issue keeps
 * all its rows and is hidden by the Issue model's SoftDeletes scope. Project admins (project.trash) restore it or
 * delete it for good, which removes every row that references it.
 */
class IssueTrashService
{
    public const BULK_MAX = 200;

    /** Event types of the recycle bin; the project 異動紀錄 page lists these issue events. */
    public const EVENTS = ['issue_deleted', 'issue_restored', 'issue_purged'];

    public function __construct(
        private IssueRepository $issues,
        private EventRepository $events,
        private MeetingRepository $meetings,
        private WorkflowService $wf,
        private SettlementService $settle,
        private AttachmentService $files,
        private UnitOfWork $uow,
    ) {}

    /** Why $u may not delete $i (null = allowed). */
    public function blocker(User $u, Issue $i): ?string
    {
        if (! $u->seesInternal()) {
            return '找不到這張內部單。';
        }
        $deletable = $this->wf->lookupCodes('issue_deletable');
        if (! in_array($i->status, $deletable, true)) {
            $names = implode('、', array_map(fn ($c) => '「'.$this->wf->label('issue', $c).'」', $deletable));

            return "只有{$names}狀態的內部單可以刪除，其它請用取消。";
        }
        $own = $i->created_by === $u->id && $this->wf->can($u, 'issue.create', $i->project_id);
        if (! $own && ! $this->wf->can($u, 'issue.delete', $i->project_id)) {
            return '沒有權限刪除這張內部單（只有建立者或內部 PM 可以刪除）。';
        }
        if ($i->package_id !== null) {
            return '這張單已排入更新包，請先移出更新包。';
        }

        return null;
    }

    public function canDelete(User $u, Issue $i): bool
    {
        return $this->blocker($u, $i) === null;
    }

    /** Whether the list page offers 刪除選取 (each issue is still checked on submit). */
    public function canDeleteSome(User $u, int $pid): bool
    {
        return $u->seesInternal() && ($this->wf->can($u, 'issue.delete', $pid) || $this->wf->can($u, 'issue.create', $pid));
    }

    public function delete(User $u, int $pid, int $id): Issue
    {
        return $this->uow->run(function () use ($u, $pid, $id) {
            $i = $this->issues->findForUpdate($id);
            if (! $i || $i->project_id !== $pid || ! $u->seesInternal()) {
                throw new BusinessException('找不到這張內部單。');
            }
            if ($why = $this->blocker($u, $i)) {
                throw new BusinessException($why);
            }
            $this->trash($u, $i);

            return $i;
        });
    }

    /**
     * Delete many issues at once (the list page); issues that may not be deleted are skipped with the reason.
     *
     * @param  int[]  $ids
     * @return array{deleted: string[], skipped: array<int, array{no:string, reason:string}>}
     */
    public function bulkDelete(User $u, int $pid, array $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if (! $ids) {
            throw new BusinessException('請先勾選內部單。');
        }
        if (count($ids) > self::BULK_MAX) {
            throw new BusinessException('一次最多刪除 '.self::BULK_MAX.' 張。');
        }

        return $this->uow->run(function () use ($u, $pid, $ids) {
            $out = ['deleted' => [], 'skipped' => []];
            $found = $this->issues->lockMany($ids)->filter(fn (Issue $i) => $i->project_id === $pid);
            foreach ($found as $i) {
                if ($why = $this->blocker($u, $i)) {
                    $out['skipped'][] = ['no' => $i->issue_no, 'reason' => $why];

                    continue;
                }
                $this->trash($u, $i);
                $out['deleted'][] = $i->issue_no;
            }
            if ($missing = count($ids) - $found->count()) {
                $out['skipped'][] = ['no' => "{$missing} 張", 'reason' => '找不到（可能已被刪除）'];
            }

            return $out;
        });
    }

    private function trash(User $u, Issue $i): void
    {
        $this->issues->moveToTrash($i, $u->id);
        $this->events->log($i->project_id, 'issue', $i->id, 'issue_deleted', $i->status, null, $u->id, [
            'issue' => $i->issue_no, 'title' => $i->title, 'tickets' => $i->tickets()->pluck('ticket_no')->all(),
        ]);
        // linked client tickets: with no live issue left they ask PM to act (same path as all issues cancelled)
        $this->settle->recompute($i->id, $u->id);
    }

    /** @return Collection<int, Issue> */
    public function trashed(User $u, int $pid): Collection
    {
        $this->wf->authorize($u, 'project.trash', $pid);

        return $this->issues->trashed($pid);
    }

    public function canTrash(User $u, int $pid): bool
    {
        return $this->wf->can($u, 'project.trash', $pid);
    }

    public function restore(User $u, int $pid, int $id): Issue
    {
        $this->wf->authorize($u, 'project.trash', $pid);

        return $this->uow->run(function () use ($u, $pid, $id) {
            $i = $this->lockedTrashed($pid, $id);
            $this->issues->restoreFromTrash($i);
            $this->events->log($pid, 'issue', $i->id, 'issue_restored', null, $i->status, $u->id, ['issue' => $i->issue_no, 'title' => $i->title]);

            return $i;
        });
    }

    /** Permanent delete; the caller must type the exact issue number. */
    public function purge(User $u, int $pid, int $id, string $confirmNo): void
    {
        $this->wf->authorize($u, 'project.trash', $pid);
        $this->uow->run(function () use ($u, $pid, $id, $confirmNo) {
            $i = $this->lockedTrashed($pid, $id);
            if (mb_strtoupper(trim($confirmNo)) !== mb_strtoupper($i->issue_no)) {
                throw new BusinessException('輸入的單號不符，未刪除。');
            }
            $this->events->log($pid, 'issue', $i->id, 'issue_purged', null, null, $u->id, [
                'issue' => $i->issue_no, 'title' => $i->title, 'origin' => $i->origin, 'created_by' => $i->created_by,
                'deleted_by' => $i->deleted_by, 'deleted_at' => (string) $i->deleted_at,
            ]);
            $images = $this->files->ownedBy('issue', [$i->id])->flatten(1)
                ->merge($this->files->ownedBy('issue_comment', $this->issues->commentIds($i->id))->flatten(1));
            $this->meetings->notePurgedIssue($i->id, "（原關聯內部單 {$i->issue_no} 已永久刪除）");
            $this->issues->purge($i);
            $this->files->deleteFiles($images);
        });
    }

    private function lockedTrashed(int $pid, int $id): Issue
    {
        $i = $this->issues->lockTrashed($id);
        if (! $i || $i->project_id !== $pid) {
            throw new BusinessException('回收筒裡找不到這張內部單。');
        }

        return $i;
    }

    /**
     * Issue page of a deleted issue: who moved it to the recycle bin, or that it was deleted for good.
     *
     * @return array{state:string, issue_no:string, title:string, at:mixed, by:?string}|null
     */
    public function tombstone(User $u, int $pid, int $id): ?array
    {
        if (! $u->seesInternal()) {
            return null;
        }
        if ($i = $this->issues->findTrashed($id)) {
            return $i->project_id === $pid
                ? ['state' => 'trashed', 'issue_no' => $i->issue_no, 'title' => $i->title, 'at' => $i->deleted_at, 'by' => $i->deleter?->name]
                : null;
        }
        $e = $this->events->forSubject('issue', $id)->last();
        if (! $e || $e->event_type !== 'issue_purged' || $e->project_id !== $pid) {
            return null;
        }

        return ['state' => 'purged', 'issue_no' => (string) ($e->payload['issue'] ?? ''), 'title' => (string) ($e->payload['title'] ?? ''),
            'at' => $e->created_at, 'by' => $e->actor?->name];
    }
}
