<?php

namespace App\Services\Dev;

use App\Exceptions\BusinessException;
use App\Models\Issue;
use App\Models\User;
use App\Repositories\EventRepository;
use App\Repositories\IssueCloseRepository;
use App\Repositories\IssueRepository;
use App\Repositories\MesPageRepository;
use App\Repositories\PmQueryRepository;
use App\Repositories\TimelineRepository;
use App\Services\IssueService;
use App\Services\WorkflowService;
use Carbon\Carbon;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

class DevService
{
    public const TABS = ['active' => '待開發與進行中', 'all' => '全部', 'schedule' => '排程清單', 'close' => '結案收尾'];

    public const DISPATCH_LABEL = 'PM 派工時間';

    public const CLOSE_COLUMNS = [
        'issue_no' => '單號', 'title' => '標題', 'dispatched_at' => self::DISPATCH_LABEL,
        'round' => '輪次', 'target' => '結案方式', 'requested_at' => '送出結案時間',
        'status' => '收尾狀態', 'progress' => '已完成／總數', 'pending' => '待確認人員',
    ];

    public function __construct(
        private IssueRepository $issues,
        private IssueService $issueService,
        private WorkflowService $wf,
        private TimelineRepository $timeline,
        private MesPageRepository $mes,
        private PmQueryRepository $users,
        private EventRepository $events,
        private IssueCloseRepository $closeTasks,
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
        $tab = is_string($in['tab'] ?? null) && isset(self::TABS[$in['tab']]) ? $in['tab'] : 'active';
        $q = is_string($in['q'] ?? null) ? trim($in['q']) : '';
        $selected = is_string($in['status'] ?? null) ? $in['status'] : '';
        if ($tab === 'close') {
            $status = array_key_exists($selected, $this->closeStatuses()) ? $selected : 'pending';

            return ['tab' => $tab, 'mine' => ! empty($in['mine']), 'status' => $status, 'q' => $q, 'overdue' => false, 'f' => null];
        }
        $statusMap = $this->wf->statusMap('issue');
        $devCodes = $this->wf->codesForStage($this->wf->lookupValue('stage_role', 'dev'));
        $mine = isset($in['f']) ? ! empty($in['mine']) : $this->canManage($u, $pid);
        $status = isset($statusMap[$selected]) ? $selected : null;
        $overdue = ! empty($in['overdue']);
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
            'tabs' => self::TABS,
            'tab' => $tab, 'mine' => $fl['mine'], 'status' => $fl['status'], 'overdue' => $fl['overdue'], 'q' => $fl['q'],
            'statusOptions' => $statusMap->only($tab === 'all' ? $statusMap->keys()->all() : $devCodes)->map->name->all(),
            'canManage' => $this->canManage($u, $pid),
        ] + $this->issueService->packageBoard($pid) + [
            'testUsers' => $this->users->usersByIds($this->events->userIdsWithPermission($pid, 'test.manage')),
        ];

        if ($tab === 'close') {
            $data['statusOptions'] = $this->closeStatuses();
            $data['rounds'] = $this->closeRounds($u, $pid, $fl);
            $data['closeColumns'] = self::CLOSE_COLUMNS;

            return $data;
        }
        if ($tab === 'schedule') {
            $scheduled = $this->scheduleIssues($u, $pid, $fl['mine']);
            $data['groups'] = $this->scheduleGroups($scheduled);
            $data['dispatchTimes'] = $this->dispatchTimes($pid, $scheduled->pluck('id')->all());

            return $data;
        }

        $data['issues'] = $this->issues->paginate($pid, $fl['f'], 20)->appends(['f' => 1]);
        $data['dispatchTimes'] = $this->dispatchTimes($pid, $data['issues']->pluck('id')->all());
        $data['dueIds'] = $this->issues->pendingDueRequests($pid)->pluck('issue_id')->all();

        return $data;
    }

    public function dispatchTimes(int $pid, array $issueIds): Collection
    {
        return $this->events->firstDevDispatchTimes($pid, $issueIds, $this->wf->initial('issue_initial', 'dev'))
            ->map(fn ($time) => tw($time));
    }

    public function closeStatuses(): array
    {
        return ['all' => '全部', 'pending' => $this->wf->label('issue', $this->wf->lookupValue('issue_close_state', 'wrapup'))]
            + $this->wf->lookupMapAll('issue_close_task_status');
    }

    /** Same round filters, order and displayed values for HTML and Excel. */
    public function closeRounds(User $u, int $pid, array $fl, ?int $limit = null): Collection|LengthAwarePaginator
    {
        $result = $this->closeTasks->rounds($pid, $fl['status'] === 'all' ? null : $fl['status'], $fl['q'],
            $fl['mine'] ? $u->id : null, (bool) $u->is_admin, $fl['mine'] && $this->wf->can($u, 'release.manage', $pid), $limit);
        $rows = $result instanceof LengthAwarePaginator ? $result->getCollection() : $result;
        $times = $this->dispatchTimes($pid, $rows->pluck('issue_id')->unique()->values()->all());
        $roles = $this->wf->lookupMapAll('issue_close_role');
        $statuses = $this->closeStatuses();
        $pending = $this->closeTasks->pendingOwners($rows->where('round_status', 'pending')->pluck('issue_id')->all())
            ->groupBy(fn ($t) => $t->issue_id.'-'.$t->round)
            ->map(fn ($tasks) => $tasks->map(fn ($t) => ($roles[$t->role] ?? $t->role).' '.($t->name ?? '上線負責人'))->implode('、'));
        $rows->transform(fn ($r) => [
            'issue_id' => $r->issue_id, 'issue_no' => $r->issue_no, 'title' => $r->title,
            'dispatched_at' => $times[$r->issue_id] ?? '—', 'round' => (int) $r->round,
            'target' => $this->wf->label('issue', $r->target), 'requested_at' => tw($r->requested_at),
            'status' => $statuses[$r->round_status], 'progress' => $r->done_count.'／'.$r->total,
            'pending' => $pending[$r->issue_id.'-'.$r->round] ?? '—',
        ]);

        return $result;
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
