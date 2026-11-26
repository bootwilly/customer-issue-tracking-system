<?php

namespace App\Services;

use App\Exceptions\BusinessException;
use App\Models\Issue;
use App\Models\Package;
use App\Models\User;
use App\Repositories\EventRepository;
use App\Repositories\IssueRepository;
use App\Repositories\TicketRepository;
use App\Repositories\UnitOfWork;
use App\Services\Collab\DependencyService;
use App\Services\Collab\WatchService;
use Illuminate\Support\Collection;

class IssueService
{
    /** Assignee fields a transition may require: an issue that already has one keeps it. */
    private const PACKAGE_FIELD = 'package_id';

    private const KEPT_ASSIGNEES = ['assignee_dev_id', 'assignee_test_id'];

    /** Permission an assignee must hold in the project (same lists the pickers show). */
    private const ASSIGNEE_PERMISSION = ['assignee_dev_id' => 'dev.manage', 'assignee_test_id' => 'test.manage'];

    /** Fields that a transition may carry (validated against requires_fields). */
    private const TRANSITION_FIELDS = [
        'assignee_dev_id', 'assignee_test_id', 'planned_due', 'package_id', 'test_scheduled_at',
        'release_planned_at', 'cancel_reason', 'client_note',
    ];

    public function __construct(
        private IssueRepository $issues,
        private TicketRepository $tickets,
        private EventRepository $events,
        private WorkflowService $wf,
        private SettlementService $settle,
        private NumberingService $numbers,
        private ContentService $content,
        private UnitOfWork $uow,
        private WatchService $watch,
        private DependencyService $deps,
    ) {}

    public function get(int $id, int $projectId, User $u): Issue
    {
        $i = $this->issues->find($id);
        if (! $i || $i->project_id !== $projectId || ! $u->seesInternal()) {
            throw new BusinessException('找不到這張內部單。');
        }

        return $i;
    }

    /** UI helper: allowed transitions + editable fields for this user. */
    public function abilities(Issue $i, User $u): array
    {
        $fields = ['severity', 'difficulty', 'title', 'content_html', 'process_type', 'mes_page_id', 'planned_start', 'dev_note'];
        $editable = [];
        foreach ($fields as $f) {
            if ($this->wf->canEditField($u, $f, $i->origin, $i->stage, $i->created_by, $i->project_id)) {
                $editable[] = $f;
            }
        }
        $isDev = $this->wf->can($u, 'dev.manage', $i->project_id);

        return [
            // a required assignee the issue already has is not asked again (transition() reuses it)
            'targets' => $this->wf->allowedTargets('issue', $i->status, $u, $i->project_id)->map(function ($t) use ($i) {
                $t['requires'] = array_values(array_filter($t['requires'], fn ($f) => ! in_array($f, self::KEPT_ASSIGNEES, true) || $i->{$f} === null));

                return $t;
            }),
            'editable' => $editable,
            'set_due' => $isDev && $i->current_due === null && $this->wf->status('issue', $i->status)->stage === $this->wf->lookupValue('stage_role', 'dev'),
            'request_due' => $this->wf->can($u, 'due.request', $i->project_id) && $i->current_due !== null && ! $this->wf->status('issue', $i->status)->is_terminal,
            'comment' => $u->seesInternal(),
            'addendum' => $this->wf->can($u, 'issue.edit_pm', $i->project_id) || $u->id === $i->created_by,
            'link' => $this->wf->can($u, 'issue.link', $i->project_id) && ! $this->wf->status('issue', $i->status)->is_terminal,
        ];
    }

    public function timeline(Issue $i): Collection
    {
        return $this->events->forSubject('issue', $i->id)->map(fn ($e) => [
            'at' => $e->created_at,
            'label' => match ($e->event_type) {
                'created' => '建立', 'status' => '狀態：'.$this->wf->label('issue', (string) $e->to_status),
                'fields' => '更新欄位', 'linked' => '連結客戶單', 'unlinked' => '解除連結', 'comment' => '內部留言',
                'addendum' => '補充說明', 'due_request' => '申請延期', 'due_review' => '延期審核',
                default => $this->wf->lookupLabel('event_label', $e->event_type), // newer events are labelled by lookup
            },
            'actor' => $e->actor?->name,
            'note' => $e->payload['note'] ?? $this->eventNote($e->event_type, (array) $e->payload),
        ]);
    }

    /** Short detail for collaboration events (dependency: other issue + kind, tags: new set). */
    private function eventNote(string $type, array $p): ?string
    {
        return match ($type) {
            'dependency_added', 'dependency_removed' => trim(($p['issue'] ?? '').' '.(isset($p['kind']) ? $this->wf->lookupLabel('dependency_kind', $p['kind']) : '')),
            'tags_changed' => implode('、', array_map(fn ($c) => $this->wf->lookupLabel('issue_tag', $c), $p['to'] ?? [])) ?: '（無）',
            default => null,
        };
    }

    /** PM registers accepted client tickets into one internal issue (N:M). */
    public function createFromTickets(User $u, int $projectId, array $ticketIds, array $d, ?string $draft = null): Issue
    {
        $this->wf->authorize($u, 'issue.create', $projectId);
        $this->wf->authorize($u, 'issue.link', $projectId);
        if (! $ticketIds) {
            throw new BusinessException('請選擇客戶單。');
        }

        return $this->uow->run(function () use ($u, $projectId, $ticketIds, $d, $draft) {
            $tickets = $this->tickets->lockMany($ticketIds);
            foreach ($tickets as $t) {
                if ($t->project_id !== $projectId || $t->accepted_at === null || $this->wf->status('ticket', $t->status)->is_terminal) {
                    throw new BusinessException("{$t->ticket_no} 尚未接收或已結束，不能建立內部單。");
                }
            }
            $issue = $this->createRow($u, $projectId, $this->wf->lookupCodes('issue_origin_external')[0] ?? throw new BusinessException('缺少設定：issue_origin_external'), $d, $draft);
            foreach ($tickets as $t) {
                $this->tickets->link($t->id, $issue->id, $t->current_rev, $u->id);
                $this->tickets->save($t, ['needs_pm_action' => false]);
                $this->events->log($projectId, 'ticket', $t->id, 'linked', null, null, $u->id, ['issue' => $issue->issue_no]);
            }
            $this->tickets->ackRevisions($tickets->first()->id, $u->id);

            return $issue;
        });
    }

    /** Internal issue created by PM / DEV / TEST (never visible to clients). */
    public function createInternal(User $u, int $projectId, string $origin, array $d, ?string $draft = null): Issue
    {
        $perm = $this->wf->initial('issue_create_perm', $origin);
        $this->wf->authorize($u, 'issue.create', $projectId);
        $this->wf->authorize($u, $perm, $projectId);

        return $this->uow->run(fn () => $this->createRow($u, $projectId, $origin, $d, $draft));
    }

    private function createRow(User $u, int $projectId, string $origin, array $d, ?string $draft): Issue
    {
        $html = $this->content->clean($d['content_html'] ?? '');
        if ($this->content->isBlank($html) || trim((string) ($d['title'] ?? '')) === '') {
            throw new BusinessException('標題與內容不可空白。');
        }
        $status = $this->wf->initial('issue_initial', $origin);
        $st = $this->wf->status('issue', $status);
        $this->checkScale($d['severity'] ?? null, '嚴重程度');
        $issue = $this->issues->create([
            'project_id' => $projectId,
            'issue_no' => $this->numbers->next($projectId, 'issue'),
            'origin' => $origin,
            'process_type' => $d['process_type'] ?? null,
            'mes_page_id' => $d['mes_page_id'] ?? null,
            'severity' => $d['severity'] ?? null,
            'title' => $d['title'],
            'content_html' => $html,
            'stage' => $st->stage,
            'status' => $status,
            'assignee_dev_id' => $d['assignee_dev_id'] ?? null,
            'assignee_test_id' => $d['assignee_test_id'] ?? null,
            'content_locked_at' => $st->stage !== $this->pmStage() ? now() : null,
            'rework_count' => 0,
            'created_by' => $u->id,
        ]);
        $this->events->log($projectId, 'issue', $issue->id, 'created', null, $status, $u->id, ['origin' => $origin]);
        $this->content->bind($html, 'issue', $issue->id, $draft);
        $this->content->clearDraft($u->id, $draft);
        $this->watch->autoWatchIssue($issue, [$u->id, $issue->assignee_dev_id, $issue->assignee_test_id]);
        if ($st->stage !== $this->pmStage()) {
            $this->notifyStage($issue, "新內部單 {$issue->issue_no}：{$issue->title}", $u->id);
        }

        return $issue;
    }

    /** Edit fields; each field checked against field_edit_rules. */
    public function updateFields(User $u, int $id, int $projectId, array $d): Issue
    {
        return $this->uow->run(function () use ($u, $id, $projectId, $d) {
            $i = $this->locked($id, $projectId, $u);
            $changes = [];
            foreach ($d as $field => $value) {
                if (! $this->wf->canEditField($u, $field, $i->origin, $i->stage, $i->created_by, $projectId)) {
                    throw new BusinessException("沒有權限修改欄位：$field");
                }
                if (in_array($field, ['title', 'content_html'], true) && $i->content_locked_at) {
                    throw new BusinessException('已拋單，主體內容已鎖定，只能新增補充說明。');
                }
                if ($field === 'content_html') {
                    $value = $this->content->clean((string) $value);
                }
                if (in_array($field, ['severity', 'difficulty'], true)) {
                    $this->checkScale($value, $field === 'severity' ? '嚴重程度' : '難易度');
                }
                $current = $i->{$field} instanceof \DateTimeInterface ? $i->{$field}->format('Y-m-d') : $i->{$field};
                if ($current != $value) {
                    $changes[$field] = $value;
                }
            }
            if ($changes) {
                $this->issues->save($i, $changes);
                $this->events->log($projectId, 'issue', $i->id, 'fields', null, null, $u->id, ['fields' => array_keys($changes)]);
            }

            return $i;
        });
    }

    /** DEV sets the first expected completion date. Later changes need a due change request. */
    public function setInitialDue(User $u, int $id, int $projectId, string $date): Issue
    {
        return $this->uow->run(function () use ($u, $id, $projectId, $date) {
            $i = $this->locked($id, $projectId, $u);
            $this->wf->authorize($u, 'dev.manage', $projectId);
            if ($i->current_due !== null) {
                throw new BusinessException('預計完工日已設定，延後需提出延期申請。');
            }
            $this->issues->save($i, ['planned_due' => $date, 'current_due' => $date]);
            $this->events->log($projectId, 'issue', $i->id, 'fields', null, null, $u->id, ['fields' => ['planned_due']]);

            return $i;
        });
    }

    /** Generic status change with side effects (dispatch lock, rework count, release, exclusion, settlement). */
    public function transition(User $u, int $id, int $projectId, string $to, array $data = [], ?string $newPackageName = null): Issue
    {
        return $this->uow->run(function () use ($u, $id, $projectId, $to, $data, $newPackageName) {
            $i = $this->locked($id, $projectId, $u);
            $name = trim($newPackageName ?? '');
            if ($name !== '') {
                $target = $this->wf->allowedTargets('issue', $i->status, $u, $projectId)->firstWhere('code', $to);
                if (! in_array(self::PACKAGE_FIELD, $target['requires'] ?? [], true)) {
                    throw new BusinessException('這個狀態變更不能指定更新包。');
                }
                $data[self::PACKAGE_FIELD] = $this->createPackage($u, $projectId, $name);
            }

            return $this->applyTransition($u, $i, $to, $data);
        });
    }

    private function applyTransition(User $u, Issue $i, string $to, array $data, ?Collection $packages = null): Issue
    {
        $projectId = $i->project_id;
        $from = $i->status;
        $fromSt = $this->wf->status('issue', $from);
        $toSt = $this->wf->status('issue', $to);
        $data = array_intersect_key($data, array_flip(self::TRANSITION_FIELDS));
        if ($toSt->is_excluded && $this->tickets->linkCountOfIssue($i->id) > 0 && trim((string) ($data['client_note'] ?? '')) === '') {
            throw new BusinessException('此內部單有連結客戶單，請填寫給客戶看的說明。');
        }
        foreach (self::ASSIGNEE_PERMISSION as $f => $perm) {
            if (! empty($data[$f]) && (int) $data[$f] !== $i->{$f}
                && ! in_array((int) $data[$f], array_map('intval', $this->events->userIdsWithPermission($projectId, $perm)), true)) {
                throw new BusinessException('指派的人員沒有這個專案的對應權限。');
            }
        }
        foreach (self::KEPT_ASSIGNEES as $f) {
            if (empty($data[$f]) && $i->{$f} !== null) {
                $data[$f] = $i->{$f};
            }
        }
        if (array_key_exists('planned_due', $data) && $i->current_due !== null) {
            unset($data['planned_due']); // already set: never overwritten by a transition
        }
        $this->wf->assertTransition('issue', $from, $to, $u, $projectId, $data + ($i->current_due ? ['planned_due' => $i->current_due] : []));
        $this->deps->assertCanEnter($i, $fromSt, $toSt);

        $set = $data + ['status' => $to, 'stage' => $toSt->stage ?? $i->stage];
        if (isset($data['planned_due'])) {
            $set['current_due'] = $data['planned_due'];
        }
        $leavingPm = $fromSt->stage === $this->pmStage() && $toSt->stage !== null && $toSt->stage !== $this->pmStage();
        if ($leavingPm) {
            $set['content_locked_at'] = now();
        }
        if ($fromSt->stage === $this->wf->lookupValue('stage_role', 'test') && $toSt->stage === $this->wf->lookupValue('stage_role', 'dev')) {
            $set['rework_count'] = $i->rework_count + 1;
        }
        $hasPackage = array_key_exists(self::PACKAGE_FIELD, $data);
        $packageId = $i->package_id;
        if ($hasPackage) {
            $packageId = isset($data[self::PACKAGE_FIELD]) ? (int) $data[self::PACKAGE_FIELD] : null;
        }
        $changingPackage = $packageId !== $i->package_id;
        if ($hasPackage || $toSt->is_released) {
            if ($changingPackage && ($fromSt->is_released || $i->released_at !== null)) {
                throw new BusinessException("{$i->issue_no} 已上線，不能變更所屬更新包。");
            }
            $packages ??= $this->lockPackages($projectId, [$i->package_id, $packageId]);
            $releasedCodes = $this->releasedIssueCodes();
            if ($changingPackage && $i->package_id !== null) {
                $this->assertPackageOpen($packages->get($i->package_id), $releasedCodes);
            }
            $package = $packageId !== null ? $packages->get($packageId) : null;
            if ($changingPackage && $package) {
                $this->assertPackageOpen($package, $releasedCodes);
            }
            if ($hasPackage && $package && ! $toSt->is_released) {
                $set['actual_done_at'] = now();
            }
            if ($toSt->is_released) {
                $set['released_at'] = $i->released_at ?? now();
                // The first confirmation freezes the package. Older packages may already be frozen without a reliable date.
                if ($package && ! $this->packageIsReleased($package, $releasedCodes)) {
                    $this->issues->markPackageReleased($package, $set['released_at']);
                }
            }
        }
        $this->issues->save($i, $set);
        $this->events->log($projectId, 'issue', $i->id, 'status', $from, $to, $u->id,
            array_filter(['note' => $data['cancel_reason'] ?? null]));

        if ($leavingPm || ($toSt->stage !== null && $toSt->stage !== $this->pmStage())) {
            $this->chainLock($i, $u->id);
        }
        $this->watch->autoWatchIssue($i, [$i->assignee_dev_id, $i->assignee_test_id]);
        if ($toSt->stage !== $fromSt->stage) {
            $this->notifyStage($i, "{$i->issue_no} 狀態：{$toSt->name}", $u->id);
        }
        $this->settle->recompute($i->id, $u->id);

        return $i;
    }

    /** Link another client ticket to an existing issue (problem convergence). */
    public function link(User $u, int $id, int $projectId, int $ticketId): void
    {
        $this->uow->run(function () use ($u, $id, $projectId, $ticketId) {
            $this->wf->authorize($u, 'issue.link', $projectId);
            $t = $this->tickets->lockMany([$ticketId])->first();
            $i = $this->locked($id, $projectId, $u);
            if (! $t || $t->project_id !== $projectId || $t->accepted_at === null) {
                throw new BusinessException('客戶單不存在或尚未接收。');
            }
            $this->tickets->link($t->id, $i->id, $t->current_rev, $u->id);
            $this->tickets->save($t, ['needs_pm_action' => false]);
            $this->events->log($projectId, 'issue', $i->id, 'linked', null, null, $u->id, ['ticket' => $t->ticket_no]);
            $this->events->log($projectId, 'ticket', $t->id, 'linked', null, null, $u->id, ['issue' => $i->issue_no]);
            if ($i->stage !== $this->pmStage()) {
                $this->chainLock($i, $u->id);
            }
            $this->settle->recompute($i->id, $u->id);
        });
    }

    public function requestDueChange(User $u, int $id, int $projectId, string $newDue, string $reason): void
    {
        $this->uow->run(function () use ($u, $id, $projectId, $newDue, $reason) {
            $this->wf->authorize($u, 'due.request', $projectId);
            $i = $this->locked($id, $projectId, $u);
            if (trim($reason) === '') {
                throw new BusinessException('請填寫延期原因。');
            }
            $this->issues->createDueRequest(['issue_id' => $i->id, 'old_due' => $i->current_due, 'new_due' => $newDue, 'reason' => $reason, 'requested_by' => $u->id]);
            $this->events->log($projectId, 'issue', $i->id, 'due_request', null, null, $u->id, ['note' => "{$newDue}：{$reason}"]);
            $this->events->notify($this->events->userIdsWithPermission($projectId, 'due.approve'), $projectId, "{$i->issue_no} 申請延期至 $newDue", "/issues/{$i->id}", $this->mail());
        });
    }

    public function reviewDueChange(User $u, int $requestId, int $projectId, bool $approve): void
    {
        $this->uow->run(function () use ($u, $requestId, $projectId, $approve) {
            $this->wf->authorize($u, 'due.approve', $projectId);
            $r = $this->issues->findDueRequestForUpdate($requestId) ?? throw new BusinessException('申請不存在。');
            if ($r->status !== 'pending') {
                throw new BusinessException('此申請已審核。');
            }
            $i = $this->locked($r->issue_id, $projectId, $u);
            $this->issues->saveDueRequest($r, ['status' => $approve ? 'approved' : 'rejected', 'reviewed_by' => $u->id, 'reviewed_at' => now()]);
            if ($approve) {
                $this->issues->save($i, ['current_due' => $r->new_due]);
            }
            $this->events->log($projectId, 'issue', $i->id, 'due_review', null, null, $u->id, ['note' => ($approve ? '核准' : '駁回').' '.$r->new_due->toDateString()]);
            $this->events->notify([$r->requested_by], $projectId, "{$i->issue_no} 延期申請已".($approve ? '核准' : '駁回'), "/issues/{$i->id}", $this->mail());
        });
    }

    public function createPackage(User $u, int $projectId, string $name): int
    {
        $this->wf->authorize($u, 'dev.manage', $projectId);
        if (trim($name) === '') {
            throw new BusinessException('請輸入更新包名稱。');
        }

        return $this->issues->createPackage($projectId, trim($name), $u->id)->id;
    }

    public function packages(int $projectId, ?int $currentPackageId = null): Collection
    {
        $releasedCodes = $this->releasedIssueCodes();
        $current = $currentPackageId === null ? null : $this->issues->packageWithReleaseState($projectId, $currentPackageId, $releasedCodes);
        if ($current && ($current->released_at || $current->has_released_issue)) {
            return collect([$current]);
        }

        $open = $this->issues->packages($projectId, $releasedCodes, openOnly: true);
        if ($current && ! $open->contains('id', $current->id)) {
            $open->prepend($current);
        }

        return $open;
    }

    /** @return string[] issue statuses with a hand-off transition that requires a package (DEV done -> waiting for test) */
    public function packageCandidateCodes(): array
    {
        return $this->wf->statusMap('issue')->keys()
            ->filter(fn ($code) => $this->wf->targetRequiring('issue', $code, self::PACKAGE_FIELD) !== null)->values()->all();
    }

    /** Package list data: packages, their issues, and the issues that may still be added. */
    public function packageBoard(int $projectId): array
    {
        $releasedCodes = $this->releasedIssueCodes();
        $packages = $this->issues->packages($projectId, $releasedCodes);
        $candidateCodes = $this->packageCandidateCodes();

        return [
            'packages' => $packages,
            'packageIssues' => $this->issues->issuesOfPackages($packages->pluck('id')->all()),
            'packageCandidates' => $this->issues->packageCandidates($projectId, $candidateCodes, $releasedCodes),
            'packageHandoffCodes' => $candidateCodes,
            'packageTarget' => $candidateCodes ? $this->wf->label('issue', (string) $this->wf->targetRequiring('issue', $candidateCodes[0], self::PACKAGE_FIELD)) : null,
        ];
    }

    /**
     * Put several finished fixes into one package at once: each issue takes its package hand-off transition
     * (e.g. 開發中 -> 待測試) with this package and the chosen tester. All or nothing.
     * $testerId may be null only when every issue already has a tester (kept, as in a single hand-off).
     *
     * @param  int[]  $issueIds
     */
    public function addToPackage(User $u, int $projectId, int $packageId, array $issueIds, ?int $testerId = null): int
    {
        $this->wf->authorize($u, 'dev.manage', $projectId);
        $issueIds = array_values(array_unique(array_map('intval', $issueIds)));
        if (! $issueIds) {
            throw new BusinessException('請勾選要套入的問題單。');
        }
        $allowed = $this->packageCandidateCodes();
        sort($issueIds, SORT_NUMERIC);

        return $this->uow->run(function () use ($u, $projectId, $packageId, $issueIds, $allowed, $testerId) {
            $issues = collect($issueIds)->map(fn (int $id) => $this->locked($id, $projectId, $u));
            $packages = $this->lockPackages($projectId, [...$issues->pluck(self::PACKAGE_FIELD)->all(), $packageId]);
            $p = $packages->get($packageId);
            foreach ($issues as $i) {
                if (! in_array($i->status, $allowed, true)) {
                    throw new BusinessException("{$i->issue_no} 目前的狀態不能套入更新包。");
                }
                if ($testerId === null && $i->assignee_test_id === null) {
                    throw new BusinessException("{$i->issue_no} 還沒有 Test 負責人，請指定測試人員。");
                }
                $to = $this->wf->targetRequiring('issue', $i->status, self::PACKAGE_FIELD);
                try {
                    $this->applyTransition($u, $i, $to, array_filter([self::PACKAGE_FIELD => $p->id, 'assignee_test_id' => $testerId]), $packages);
                } catch (BusinessException $e) {
                    throw new BusinessException("{$i->issue_no}：".$e->getMessage());
                }
                $this->events->log($projectId, 'issue', $i->id, 'package_set', null, null, $u->id, ['note' => $p->name]);
            }

            return $issues->count();
        });
    }

    /**
     * Remove an issue from a package.
     *  - still in DEV (not handed off yet): only the package is cleared;
     *  - handed off but testing not started (a 'system' transition back to the DEV stage exists): withdrawn from test,
     *    back to DEV, done time cleared, tester kept for the next hand-off, tester + watchers notified, rework count untouched;
     *  - testing started / released: refused (the tester sends it back as failed).
     */
    /** @return bool true = withdrawn from test (status went back to DEV) */
    public function removeFromPackage(User $u, int $projectId, int $packageId, int $issueId): bool
    {
        $this->wf->authorize($u, 'dev.manage', $projectId);

        return $this->uow->run(function () use ($u, $projectId, $packageId, $issueId) {
            $i = $this->locked($issueId, $projectId, $u);
            if ($i->package_id !== $packageId) {
                throw new BusinessException('這張單不在此更新包。');
            }
            $p = $this->lockPackages($projectId, [$packageId])->get($packageId);
            $this->assertPackageOpen($p, $this->releasedIssueCodes());
            if (in_array($i->status, $this->packageCandidateCodes(), true)) {
                $this->issues->save($i, [self::PACKAGE_FIELD => null]);
                $this->events->log($projectId, 'issue', $i->id, 'package_unset', null, null, $u->id, ['note' => $p->name]);

                return false;
            }
            $devStage = $this->wf->lookupValue('stage_role', 'dev');
            $back = $this->wf->targetByPermission('issue', $i->status, WorkflowService::SYSTEM, fn ($s) => $s->stage === $devStage);
            if ($back === null) {
                throw new BusinessException("{$i->issue_no} 已開始測試或已上線，不能移出更新包；請由測試人員判定「".$this->failedLabel().'」退回。');
            }
            $from = $i->status;
            $this->wf->assertTransition('issue', $from, $back, null, $projectId); // system transition, authorized above
            $toSt = $this->wf->status('issue', $back);
            $this->issues->save($i, ['status' => $back, 'stage' => $toSt->stage, self::PACKAGE_FIELD => null, 'actual_done_at' => null]);
            $this->events->log($projectId, 'issue', $i->id, 'test_withdrawn', $from, $back, $u->id, ['note' => $p->name]);
            $this->watch->notifyIssue($i, "{$i->issue_no} 已撤回送測（移出更新包 {$p->name}）", $u->id, [$i->assignee_test_id, $i->assignee_dev_id]);
            $this->settle->recompute($i->id, $u->id);

            return true;
        });
    }

    /** Label of the DEV-stage status a tester sends a failed issue back to (for messages). */
    private function failedLabel(): string
    {
        $test = $this->wf->lookupValue('stage_role', 'test');
        $dev = $this->wf->lookupValue('stage_role', 'dev');
        foreach ($this->wf->statusMap('issue')->filter(fn ($s) => $s->stage === $test) as $code => $s) {
            $t = $this->wf->targetByPermission('issue', $code, 'test.manage', fn ($x) => $x->stage === $dev);
            if ($t !== null) {
                return $this->wf->label('issue', $t);
            }
        }

        return $dev;
    }

    /** Package writers lock issues first, then each affected package in ascending order. */
    private function lockPackages(int $projectId, array $packageIds): Collection
    {
        return collect($packageIds)->reject(fn ($id) => $id === null)->unique()->sort(SORT_NUMERIC)
            ->mapWithKeys(function (int $id) use ($projectId) {
                $package = $this->issues->findPackageForUpdate($id);
                if (! $package || $package->project_id !== $projectId) {
                    throw new BusinessException('更新包不存在。');
                }

                return [$id => $package];
            });
    }

    private function releasedIssueCodes(): array
    {
        return $this->wf->statusMap('issue')->filter(fn ($status) => $status->is_released)->keys()->all();
    }

    private function packageIsReleased(Package $package, array $releasedCodes): bool
    {
        return $package->released_at !== null || $this->issues->hasReleasedPackageIssue($package, $releasedCodes);
    }

    private function assertPackageOpen(Package $package, array $releasedCodes): void
    {
        if ($this->packageIsReleased($package, $releasedCodes)) {
            throw new BusinessException("更新包 {$package->name} 已上線，不能再變更內容。");
        }
    }

    public function addComment(User $u, int $id, int $projectId, string $html, ?string $draft = null): void
    {
        $html = $this->content->clean($html);
        if ($this->content->isBlank($html)) {
            throw new BusinessException('留言內容不可空白。');
        }
        $this->uow->run(function () use ($u, $id, $projectId, $html, $draft) {
            $i = $this->get($id, $projectId, $u);
            $c = $this->issues->addComment($i->id, $html, $u->id);
            $this->events->log($projectId, 'issue', $i->id, 'comment', null, null, $u->id);
            $this->content->bind($html, 'issue_comment', $c->id, $draft);
            $this->content->clearDraft($u->id, $draft);
            $this->watch->notifyIssue($i, "{$i->issue_no} 有新內部留言", $u->id, []);
        });
    }

    public function addAddendum(User $u, int $id, int $projectId, string $html, ?string $draft = null): void
    {
        $html = $this->content->clean($html);
        if ($this->content->isBlank($html)) {
            throw new BusinessException('補充內容不可空白。');
        }
        $this->uow->run(function () use ($u, $id, $projectId, $html, $draft) {
            $i = $this->get($id, $projectId, $u);
            if (! $this->abilities($i, $u)['addendum']) {
                throw new BusinessException('沒有權限新增補充說明。');
            }
            $this->issues->addAddendum($i->id, $html, $u->id);
            $this->events->log($projectId, 'issue', $i->id, 'addendum', null, null, $u->id);
            $this->content->bind($html, 'issue', $i->id, $draft);
            $this->content->clearDraft($u->id, $draft);
        });
    }

    /** PM quotes a client supplement comment into an issue's addenda. */
    public function quoteComment(User $u, int $id, int $projectId, int $ticketCommentId): void
    {
        $this->uow->run(function () use ($u, $id, $projectId, $ticketCommentId) {
            $this->wf->authorize($u, 'issue.edit_pm', $projectId);
            $i = $this->get($id, $projectId, $u);
            $c = $this->issues->ticketComment($ticketCommentId) ?? throw new BusinessException('留言不存在。');
            $this->issues->addAddendum($i->id, '<blockquote>'.$c->content_html.'</blockquote>', $u->id, $c->id);
            $this->events->log($projectId, 'issue', $i->id, 'addendum', null, null, $u->id, ['note' => '引用客戶留言']);
        });
    }

    // ---------- helpers ----------

    private function locked(int $id, int $projectId, User $u): Issue
    {
        $i = $this->issues->findForUpdate($id);
        if (! $i || $i->project_id !== $projectId || ! $u->seesInternal()) {
            throw new BusinessException('找不到這張內部單。');
        }

        return $i;
    }

    /** Set dispatched_at on all linked tickets (fixed lock order). */
    private function chainLock(Issue $i, int $actorId): void
    {
        $tickets = $this->tickets->ticketsOfIssue($i->id, true);
        // Business rule: dispatch is refused while a client holds a valid edit lock (the whole transaction rolls back).
        foreach ($tickets as $t) {
            if ($t->dispatched_at === null && $t->edit_lock_user_id !== null && $t->edit_lock_expires_at?->isFuture()) {
                throw new BusinessException("客戶正在編輯客戶單 {$t->ticket_no}，請等客戶儲存或鎖定逾時後再派工。");
            }
        }
        foreach ($tickets as $t) {
            if ($t->dispatched_at === null) {
                $this->tickets->save($t, ['dispatched_at' => now(), 'edit_lock_user_id' => null, 'edit_lock_expires_at' => null]);
                $this->events->log($i->project_id, 'ticket', $t->id, 'dispatched', null, null, $actorId, ['issue' => $i->issue_no]);
            }
        }
    }

    private function pmStage(): ?string
    {
        return $this->wf->status('issue', $this->wf->initial('issue_initial', 'pm'))->stage;
    }

    private function checkScale(mixed $v, string $label): void
    {
        if ($v !== null && $v !== '' && ((int) $v < 1 || (int) $v > 5)) {
            throw new BusinessException("{$label}必須是 1～5。");
        }
    }

    /** Assignees + issue watchers, without the user who made the change. */
    private function notifyStage(Issue $i, string $title, ?int $actorId): void
    {
        $this->watch->notifyIssue($i, $title, $actorId, [$i->assignee_dev_id, $i->assignee_test_id]);
    }

    private function mail(): bool
    {
        return $this->wf->setting('mail.enabled', '0') === '1';
    }
}
