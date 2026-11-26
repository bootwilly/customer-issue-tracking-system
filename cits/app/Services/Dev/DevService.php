<?php

namespace App\Services\Dev;

use App\Exceptions\BusinessException;
use App\Models\Issue;
use App\Models\User;
use App\Repositories\IssueRepository;
use App\Repositories\MesPageRepository;
use App\Repositories\TimelineRepository;
use App\Services\IssueService;
use App\Services\WorkflowService;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class DevService
{
    public function __construct(
        private IssueRepository $issues,
        private IssueService $issueService,
        private WorkflowService $wf,
        private TimelineRepository $timeline,
        private MesPageRepository $mes,
        private \App\Repositories\PmQueryRepository $users,
        private \App\Repositories\EventRepository $events,
    ) {}

    public function canView(User $u, int $pid): bool
    {
        return $u->seesInternal() && $this->wf->can($u, 'issue.view', $pid);
    }

    public function canManage(User $u, int $pid): bool
    {
        return $this->wf->can($u, 'dev.manage', $pid);
    }

    /** @return string[] non-terminal issue status codes */
    public function openCodes(): array
    {
        return $this->wf->statusMap('issue')->where('is_terminal', false)->keys()->all();
    }

    /**
     * Normalised filters of the dev page; 'f' is the exact array passed to IssueRepository::paginate (null on the schedule tab).
     *
     * @return array{tab:string,mine:bool,status:?string,overdue:bool,q:string,f:?array}
     */
    public function filters(User $u, int $pid, array $in): array
    {
        $tab = in_array($in['tab'] ?? '', ['active', 'all', 'schedule'], true) ? $in['tab'] : 'active';
        $statusMap = $this->wf->statusMap('issue');
        $devCodes = $this->wf->codesForStage($this->wf->lookupValue('stage_role', 'dev'));
        $mine = isset($in['f']) ? ! empty($in['mine']) : $this->canManage($u, $pid);
        $status = isset($in['status'], $statusMap[$in['status']]) ? $in['status'] : null;
        $overdue = ! empty($in['overdue']);
        $q = trim((string) ($in['q'] ?? ''));
        $out = ['tab' => $tab, 'mine' => $mine, 'status' => $status, 'overdue' => $overdue, 'q' => $q, 'f' => null];
        if ($tab === 'schedule') {
            return $out;
        }

        $open = $this->openCodes();
        $statuses = $tab === 'active' ? $devCodes : null;
        if ($status) {
            $statuses = $statuses === null || in_array($status, $statuses, true) ? [$status] : [];
        }
        if ($overdue) {
            $statuses = array_values(array_intersect($statuses ?? $open, $open));
        }
        $out['f'] = [
            'statuses' => $statuses === [] ? ['-'] : $statuses,
            'dev' => $mine ? $u->id : null,
            'q' => $q ?: null,
            'overdue' => $overdue,
            'sort' => 'due', 'dir' => 'asc',
        ];

        return $out;
    }

    /** Schedule tab issues (open, dated), optionally only the user's. */
    public function scheduleIssues(User $u, int $pid, bool $mine): Collection
    {
        return $this->timeline->scheduledIssues($pid, $this->openCodes(), $mine ? $u->id : null);
    }

    public function index(User $u, int $pid, array $in): array
    {
        $fl = $this->filters($u, $pid, $in);
        $tab = $fl['tab'];
        $statusMap = $this->wf->statusMap('issue');
        $devCodes = $this->wf->codesForStage($this->wf->lookupValue('stage_role', 'dev'));

        $data = [
            'tab' => $tab, 'mine' => $fl['mine'], 'status' => $fl['status'], 'overdue' => $fl['overdue'], 'q' => $fl['q'],
            'statusOptions' => $statusMap->only($tab === 'all' ? $statusMap->keys()->all() : $devCodes)->map->name->all(),
            'canManage' => $this->canManage($u, $pid),
        ] + $this->issueService->packageBoard($pid) + [
            'testUsers' => $this->users->usersByIds($this->events->userIdsWithPermission($pid, 'test.manage')),
        ];

        if ($tab === 'schedule') {
            $data['groups'] = $this->scheduleGroups($this->scheduleIssues($u, $pid, $fl['mine']));

            return $data;
        }

        $data['issues'] = $this->issues->paginate($pid, $fl['f'], 20)->appends(['f' => 1]);
        $data['dueIds'] = $this->issues->pendingDueRequests($pid)->pluck('issue_id')->all();

        return $data;
    }

    /** @return array<string, Collection> group label => issues */
    public function scheduleGroups(Collection $issues): array
    {
        $today = Carbon::now('Asia/Taipei')->startOfDay();
        $thisEnd = $today->copy()->endOfWeek()->toDateString();
        $nextEnd = $today->copy()->addWeek()->endOfWeek()->toDateString();
        $groups = ['已逾期' => [], '本週' => [], '下週' => [], '之後' => []];
        foreach ($issues as $i) {
            $d = $i->current_due->toDateString();
            $key = match (true) {
                $d < $today->toDateString() => '已逾期',
                $d <= $thisEnd => '本週',
                $d <= $nextEnd => '下週',
                default => '之後',
            };
            $groups[$key][] = $i;
        }

        return array_map(fn ($g) => collect($g), $groups);
    }

    public function formOptions(int $pid): array
    {
        return [
            'pid' => $pid,
            'processTypes' => $this->wf->lookups('process_type')->pluck('name', 'code')->all(),
            'mesPages' => $this->mes->activePages($pid),
        ];
    }

    public function createIssue(User $u, int $pid, array $d, ?string $draft): Issue
    {
        $this->wf->authorize($u, 'dev.manage', $pid);
        if (! empty($d['mes_page_id']) && ! $this->mes->activePages($pid)->contains('id', (int) $d['mes_page_id'])) {
            throw new BusinessException('MES 頁面不存在或已停用。');
        }
        $d['mes_page_id'] = ! empty($d['mes_page_id']) ? (int) $d['mes_page_id'] : null;
        $d['assignee_dev_id'] = $u->id; // the DEV who found it owns it (shows under "mine")

        return $this->issueService->createInternal($u, $pid, 'dev', $d, $draft);
    }

    public function createPackage(User $u, int $pid, string $name): int
    {
        return $this->issueService->createPackage($u, $pid, $name);
    }

    /** @param int[] $issueIds */
    public function addToPackage(User $u, int $pid, int $packageId, array $issueIds, ?int $testerId): int
    {
        return $this->issueService->addToPackage($u, $pid, $packageId, $issueIds, $testerId);
    }

    public function removeFromPackage(User $u, int $pid, int $packageId, int $issueId): bool
    {
        return $this->issueService->removeFromPackage($u, $pid, $packageId, $issueId);
    }
}
