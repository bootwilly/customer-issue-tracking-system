<?php

namespace App\Services\Test;

use App\Exceptions\BusinessException;
use App\Models\Issue;
use App\Models\User;
use App\Repositories\EventRepository;
use App\Repositories\IssueRepository;
use App\Repositories\MesPageRepository;
use App\Repositories\UserRepository;
use App\Services\IssueCloseListService;
use App\Services\IssueDispatchService;
use App\Services\IssueService;
use App\Services\WorkflowService;

class TestService
{
    public const TABS = ['active' => '測試與上線', 'bounced' => '已退回/新發現', 'close' => '結案收尾'];

    public function __construct(
        private IssueRepository $issues,
        private IssueService $issueService,
        private WorkflowService $wf,
        private MesPageRepository $mes,
        private UserRepository $users,
        private EventRepository $events,
        private IssueCloseListService $closeList,
        private IssueDispatchService $dispatch,
    ) {}

    public function canView(User $u, int $pid): bool
    {
        return $u->seesInternal() && $this->wf->can($u, 'issue.view', $pid);
    }

    public function canManage(User $u, int $pid): bool
    {
        return $this->wf->can($u, 'test.manage', $pid);
    }

    /**
     * Normalised filters of the test page; 'f' is the exact array passed to IssueRepository::paginate.
     *
     * @return array{tab:string,mine:bool,status:?string,q:string,base?:string[],f:?array}
     */
    public function filters(User $u, int $pid, array $in): array
    {
        $tab = is_string($in['tab'] ?? null) && isset(self::TABS[$in['tab']]) ? $in['tab'] : 'active';
        if ($tab === 'close') {
            return $this->closeList->filters($in);
        }
        $statusMap = $this->wf->statusMap('issue');
        $stageCodes = array_merge($this->wf->codesForStage($this->wf->lookupValue('stage_role', 'test')), $this->wf->codesForStage($this->wf->lookupValue('stage_role', 'release')));
        $base = $tab === 'bounced'
            ? array_values(array_intersect($this->wf->lookupCodes('test_bounced_status'), $statusMap->keys()->all()))
            : $stageCodes;
        $mine = ! empty($in['mine']);
        $status = isset($in['status']) && in_array($in['status'], $base, true) ? $in['status'] : null;
        $q = is_string($in['q'] ?? null) ? trim($in['q']) : '';

        return [
            'tab' => $tab, 'mine' => $mine, 'status' => $status, 'q' => $q, 'base' => $base,
            'f' => [
                'statuses' => $status ? [$status] : ($base ?: ['-']),
                'test' => $mine ? $u->id : null,
                'q' => $q ?: null,
                'sort' => 'test_date', 'dir' => 'asc',
            ],
        ];
    }

    public function index(User $u, int $pid, array $in): array
    {
        $fl = $this->filters($u, $pid, $in);
        $data = ['tabs' => self::TABS, 'tab' => $fl['tab'], 'mine' => $fl['mine'], 'status' => $fl['status'],
            'q' => $fl['q'], 'canManage' => $this->canManage($u, $pid)];
        if ($fl['tab'] === 'close') {
            return $data + $this->closeList->page($u, $pid, $fl, testing: true);
        }
        $issues = $this->issues->paginate($pid, $fl['f'], 20)->appends(['f' => 1]);

        return $data + [
            'issues' => $issues,
            'testDispatches' => $this->dispatch->test($pid, $issues->pluck('id')->all()),
            'statusOptions' => $this->wf->statusMap('issue')->only($fl['base'])->map->name->all(),
        ];
    }

    public function formOptions(int $pid): array
    {
        $devs = [];
        foreach ($this->events->userIdsWithPermission($pid, 'dev.manage') as $id) {
            if ($user = $this->users->find($id)) {
                $devs[$user->id] = $user->name;
            }
        }

        return ['pid' => $pid, 'devs' => $devs, 'mesPages' => $this->mes->activePages($pid)];
    }

    public function createIssue(User $u, int $pid, array $d, ?string $draft): Issue
    {
        $this->wf->authorize($u, 'test.manage', $pid);
        if (! array_key_exists((int) ($d['assignee_dev_id'] ?? 0), $this->formOptions($pid)['devs'])) {
            throw new BusinessException('請選擇有效的開發負責人。');
        }
        if (! empty($d['mes_page_id']) && ! $this->mes->activePages($pid)->contains('id', (int) $d['mes_page_id'])) {
            throw new BusinessException('MES 頁面不存在或已停用。');
        }
        $d['mes_page_id'] = ! empty($d['mes_page_id']) ? (int) $d['mes_page_id'] : null;
        $d['assignee_test_id'] = $u->id; // the finder re-tests it after DEV

        return $this->issueService->createInternal($u, $pid, 'test', $d, $draft);
    }
}
