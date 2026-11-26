<?php

namespace App\Services\Collab;

use App\Exceptions\BusinessException;
use App\Models\Issue;
use App\Models\User;
use App\Repositories\EventRepository;
use App\Repositories\IssueTagRepository;
use App\Repositories\UnitOfWork;
use App\Services\WorkflowService;

/** Controlled internal tags on issues: the list is lookup issue_tag (maintained in 系統管理 → 選項管理). Never shown to clients. */
class TagService
{
    public const CATEGORY = 'issue_tag';

    public function __construct(
        private IssueTagRepository $repo,
        private EventRepository $events,
        private WorkflowService $wf,
        private UnitOfWork $uow,
    ) {}

    public function canTag(User $u, int $projectId): bool
    {
        return $u->seesInternal() && $this->wf->can($u, 'issue.tag', $projectId);
    }

    /** @return array<string,string> active tags code => label (pickable) */
    public function options(): array
    {
        return $this->wf->lookupMap(self::CATEGORY);
    }

    /** @return array<string,string> all tags incl. disabled (for showing stored values) */
    public function labels(): array
    {
        return $this->wf->lookupMapAll(self::CATEGORY);
    }

    /** @return string[] */
    public function of(int $issueId): array
    {
        return $this->repo->codesOf($issueId);
    }

    /** @param int[] $issueIds @return array<int, string[]> */
    public function ofMany(array $issueIds): array
    {
        return $this->repo->codesOfMany($issueIds);
    }

    /** @param string[]|null $codes list filter input -> only known codes (null = no filter) */
    public function filterCodes(?array $codes): ?array
    {
        $codes = array_values(array_intersect(array_map('strval', (array) $codes), array_keys($this->labels())));

        return $codes ?: null;
    }

    /** Replace the issue's tags. Disabled tags already on the issue stay unless unticked. */
    public function set(User $u, Issue $i, array $codes): void
    {
        if (! $this->canTag($u, $i->project_id)) {
            throw new BusinessException('沒有權限修改標籤。');
        }
        $codes = array_values(array_unique(array_map('strval', $codes)));
        $current = $this->repo->codesOf($i->id);
        $allowed = array_merge(array_keys($this->options()), $current);
        if (array_diff($codes, $allowed)) {
            throw new BusinessException('標籤不存在或已停用。');
        }
        $add = array_values(array_diff($codes, $current));
        $remove = array_values(array_diff($current, $codes));
        if (! $add && ! $remove) {
            return;
        }
        $this->uow->run(function () use ($u, $i, $add, $remove, $current, $codes) {
            $this->repo->change($i->id, $add, $remove, $u->id);
            $this->events->log($i->project_id, 'issue', $i->id, 'tags_changed', null, null, $u->id, ['from' => $current, 'to' => $codes]);
        });
    }
}
