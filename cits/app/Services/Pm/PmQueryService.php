<?php

namespace App\Services\Pm;

use App\Exceptions\BusinessException;
use App\Models\User;
use App\Repositories\EventRepository;
use App\Repositories\IssueRepository;
use App\Repositories\PmQueryRepository;
use App\Services\Collab\DependencyService;
use App\Services\Collab\NeedinfoService;
use App\Services\Collab\TagService;
use App\Services\Collab\WatchService;
use App\Services\IssueService;
use App\Services\Meeting\MeetingService;
use App\Services\TicketService;
use App\Services\WorkflowService;

/** Read-side orchestration for the PM board, client-ticket view and shared issue detail. */
class PmQueryService
{
    public function __construct(
        private PmQueryRepository $repo,
        private IssueRepository $issues,
        private EventRepository $events,
        private WorkflowService $wf,
        private TicketService $tickets,
        private IssueService $issueSvc,
        private MeetingService $meetings,
        private NeedinfoService $needinfo,
        private WatchService $watch,
        private TagService $tags,
        private DependencyService $deps,
    ) {}

    /** @param array{only_action?:bool,tq?:string,ni?:string,stage?:string,status?:string,origin?:string,q?:string,sort?:string,tags?:array} $f */
    public function board(int $projectId, User $u, array $f): array
    {
        $open = $this->wf->codesForTab($this->wf->ticketScope(null));
        $scope = $f['tt'] ?? null;
        $issueStatuses = $this->wf->statusMap('issue');
        $approve = $this->wf->can($u, 'due.approve', $projectId);
        $onlyAction = (bool) ($f['only_action'] ?? true) && $this->wf->isDefaultTicketScope($scope);
        $tickets = $this->repo->pmTickets($projectId, $this->wf->ticketScopeCodes($scope), $onlyAction, $f['tq'] ?? null, $this->ticketIdFilter($projectId, $f));
        $issues = $this->issues->paginate($projectId, $this->issueFilters($f));
        $issueIds = collect($issues->items())->pluck('id')->all();

        return [
            'tickets' => $tickets,
            'counters' => $this->repo->ticketCounters($projectId, $open) + $this->needinfo->counts($projectId),
            'ticketScope' => $this->wf->ticketScope($scope),
            'ticketScopes' => $this->wf->lookupMap('client_tab'),
            'onlyActionApplies' => $this->wf->isDefaultTicketScope($scope),
            'needinfoStates' => $this->needinfo->states(collect($tickets->items())->pluck('id')->all()),
            'issues' => $issues,
            'issueTags' => $this->tags->ofMany($issueIds),
            'tagLabels' => $this->tags->labels(),
            'tagOptions' => $this->tags->options(),
            'blockers' => $this->deps->blockers($issueIds),
            'stages' => $issueStatuses->pluck('stage')->filter()->unique()->values(),
            'statuses' => $issueStatuses->values(),
            'origins' => $this->wf->lookups('issue_initial')->pluck('code'),
            'originLabels' => $this->wf->lookupMap('issue_origin'),
            'externalOrigins' => $this->wf->lookupCodes('issue_origin_external'),
            'dueRequests' => $approve ? $this->issues->pendingDueRequests($projectId) : collect(),
            'can' => [
                'create' => $this->wf->can($u, 'issue.create', $projectId) && $this->wf->can($u, 'issue.edit_pm', $projectId),
                'approve' => $approve,
            ],
        ];
    }

    /**
     * Exact filter array the PM board passes to IssueRepository::paginate.
     *
     * @param  array{stage?:string,status?:string,origin?:string,q?:string,sort?:string}  $f
     */
    public function issueFilters(array $f): array
    {
        $sort = $f['sort'] ?? 'created';
        $stage = $f['stage'] ?? '';
        $status = $f['status'] ?? '';
        $origin = $f['origin'] ?? '';

        return [
            'stages' => $stage !== '' ? [$stage] : null,
            'statuses' => $status !== '' ? [$status] : null,
            'origin' => $origin !== '' ? $origin : null,
            'q' => $f['q'] ?? null,
            'tags' => $this->tags->filterCodes($f['tags'] ?? null),
            'sort' => $sort,
            'dir' => $sort === 'due' ? 'asc' : 'desc',
        ];
    }

    /** Ticket id restriction of the PM ticket list (needinfo filter); null = none. */
    public function ticketIdFilter(int $projectId, array $f): ?array
    {
        return $this->needinfo->filterIds($projectId, $f['ni'] ?? null);
    }

    public function ticketPage(int $id, int $projectId, User $u): array
    {
        $this->tickets->get($id, $projectId); // project scope check
        $t = $this->repo->ticketDetail($id) ?? throw new BusinessException('找不到這張客戶單。');
        $terminal = $this->wf->status('ticket', $t->status)->is_terminal;
        $can = fn (string $p) => $this->wf->can($u, $p, $projectId);
        $canLink = $t->accepted_at !== null && ! $terminal;
        $this->needinfo->markSeen($u, $t);

        return [
            'ticket' => $t,
            'ab' => $this->tickets->abilities($t, $u),
            'timeline' => $this->tickets->timeline($t),
            'unackedCount' => $t->revisions->where('rev_no', '>', 1)->whereNull('pm_ack_at')->count(),
            'canCreateIssue' => $canLink && $can('issue.create') && $can('issue.link') && $can('issue.edit_pm'),
            'canLink' => $canLink && $can('issue.link'),
            'canQuote' => $can('issue.edit_pm'),
            'canAck' => $can('issue.edit_pm'),
            'processTypes' => $this->wf->lookups('process_type'),
            'mesPages' => $this->repo->mesPages($projectId),
            'otherTickets' => $this->repo->otherAcceptedTickets($projectId, $this->wf->codesForTab('open'), $t->id),
            'linkable' => $this->repo->linkableIssues($projectId, $this->openIssueStatuses(), $t->id),
            'needinfoOpen' => $this->needinfo->open($t->id),
            'needinfoHistory' => $this->needinfo->history($t->id),
            'canNeedinfo' => $this->needinfo->canRequest($u, $t),
            'watching' => $this->watch->watchingTicket($u, $t->id),
            'watchers' => $this->watch->ticketWatchers($t),
        ];
    }

    public function createFormData(int $projectId): array
    {
        return [
            'processTypes' => $this->wf->lookups('process_type'),
            'mesPages' => $this->repo->mesPages($projectId),
        ];
    }

    public function issuePage(int $id, int $projectId, User $u): array
    {
        $this->issueSvc->get($id, $projectId, $u); // scope + internal-only check
        $i = $this->repo->issueDetail($id) ?? throw new BusinessException('找不到這張內部單。');
        $ab = $this->issueSvc->abilities($i, $u);
        $targets = $ab['targets']->map(function ($t) {
            $t['needs_client_note'] = $this->wf->status('issue', $t['code'])->is_excluded;
            $t['fields'] = array_values(array_unique(array_merge($t['requires'], $t['needs_client_note'] ? ['client_note'] : [])));

            return $t;
        });
        $needs = fn (string $f) => $targets->contains(fn ($t) => in_array($f, $t['requires'], true));

        return [
            'issue' => $i,
            'ab' => $ab,
            'targets' => $targets,
            'timeline' => $this->issueSvc->timeline($i),
            'clientComments' => $this->repo->commentsOfTickets($i->tickets->pluck('id')->all()),
            'devUsers' => $needs('assignee_dev_id') ? $this->usersWith($projectId, 'dev.manage') : collect(),
            'testUsers' => $needs('assignee_test_id') ? $this->usersWith($projectId, 'test.manage') : collect(),
            'packages' => $needs('package_id') ? $this->issueSvc->packages($projectId, $i->package_id) : collect(),
            'processTypes' => $this->wf->lookups('process_type'),
            'mesPages' => $this->repo->mesPages($projectId),
            'statusMeta' => $this->wf->status('issue', $i->status),
            'linked' => $i->tickets->isNotEmpty(),
            'meetingThreads' => $this->meetings->issueThreads($u, $projectId, $i->id),
            'meetingTargets' => $this->meetings->schedulableFor($u, $projectId),
            'watching' => $this->watch->watchingIssue($u, $i->id),
            'watchers' => $this->watch->issueWatchers($i),
            'tags' => $this->tags->of($i->id),
            'tagOptions' => $this->tags->options(),
            'tagLabels' => $this->tags->labels(),
            'canTag' => $this->tags->canTag($u, $projectId),
            'deps' => $this->deps->forIssue($i),
            'depKinds' => $this->deps->kinds(),
            'canDeps' => $this->deps->canManage($u, $i),
        ];
    }

    /** Only the input fields that the chosen transition declares (plus client_note on exclusion targets). */
    public function transitionInput(int $id, int $projectId, User $u, string $to, array $input): array
    {
        $i = $this->issueSvc->get($id, $projectId, $u);
        $target = $this->issueSvc->abilities($i, $u)['targets']->firstWhere('code', $to)
            ?? throw new BusinessException('不允許這個狀態變更。');
        $fields = $target['requires'];
        if ($this->wf->status('issue', $to)->is_excluded) {
            $fields[] = 'client_note';
        }

        return array_filter(array_intersect_key($input, array_flip($fields)), fn ($v) => $v !== null && $v !== '');
    }

    /** Editable-field input for updateFields: only fields the user may edit (field_edit_rules). */
    public function editableInput(int $id, int $projectId, User $u, array $input): array
    {
        $i = $this->issueSvc->get($id, $projectId, $u);
        $out = [];
        foreach ($this->issueSvc->abilities($i, $u)['editable'] as $f) {
            if (! array_key_exists($f, $input)) {
                continue;
            }
            $v = $input[$f];
            if (in_array($f, ['title', 'content_html'], true)) {
                if ($v === null || trim((string) $v) === '') {
                    continue;
                }
            } elseif ($v === '') {
                $v = null;
            }
            // only enabled types may be picked; keeping the current (possibly disabled) value is fine
            if ($f === 'process_type' && $v !== null && $v !== $i->process_type && ! in_array($v, $this->wf->lookupCodes('process_type'), true)) {
                throw new BusinessException('處理類型無效或已停用。');
            }
            $out[$f] = $v;
        }

        return $out;
    }

    /** @return string[] */
    private function openIssueStatuses(): array
    {
        return $this->wf->statusMap('issue')->where('is_terminal', false)->keys()->all();
    }

    private function usersWith(int $projectId, string $perm)
    {
        return $this->repo->usersByIds($this->events->userIdsWithPermission($projectId, $perm));
    }
}
