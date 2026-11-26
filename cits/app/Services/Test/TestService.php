<?php

namespace App\Services\Test;

use App\Exceptions\BusinessException;
use App\Models\Issue;
use App\Models\User;
use App\Repositories\EventRepository;
use App\Repositories\IssueRepository;
use App\Repositories\MesPageRepository;
use App\Repositories\UserRepository;
use App\Services\IssueService;
use App\Services\WorkflowService;

class TestService
{
    public function __construct(
        private IssueRepository $issues,
        private IssueService $issueService,
        private WorkflowService $wf,
        private MesPageRepository $mes,
        private UserRepository $users,
        private EventRepository $events,
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
     * @return array{tab:string,mine:bool,status:?string,q:string,base:string[],f:array}
     */
    public function filters(User $u, int $pid, array $in): array
    {
        $tab = in_array($in['tab'] ?? '', ['active', 'bounced'], true) ? $in['tab'] : 'active';
        $statusMap = $this->wf->statusMap('issue');
        $stageCodes = array_merge($this->wf->codesForStage($this->wf->lookupValue('stage_role', 'test')), $this->wf->codesForStage($this->wf->lookupValue('stage_role', 'release')));
        $base = $tab === 'bounced'
            ? array_values(array_intersect($this->wf->lookupCodes('test_bounced_status'), $statusMap->keys()->all()))
            : $stageCodes;
        $mine = ! empty($in['mine']);
        $status = isset($in['status']) && in_array($in['status'], $base, true) ? $in['status'] : null;
        $q = trim((string) ($in['q'] ?? ''));

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
        $issues = $this->issues->paginate($pid, $fl['f'], 20)->appends(['f' => 1]);

        return [
            'tab' => $fl['tab'], 'mine' => $fl['mine'], 'status' => $fl['status'], 'q' => $fl['q'], 'issues' => $issues,
            'statusOptions' => $this->wf->statusMap('issue')->only($fl['base'])->map->name->all(),
            'canManage' => $this->canManage($u, $pid),
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
