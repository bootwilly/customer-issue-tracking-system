<?php

namespace App\Services;

use App\Exceptions\BusinessException;
use App\Models\ClientTicket;
use App\Models\User;
use App\Repositories\EventRepository;
use App\Repositories\IssueRepository;
use App\Repositories\TicketRepository;
use App\Repositories\UnitOfWork;
use App\Services\Collab\NeedinfoService;
use App\Services\Collab\WatchService;
use Illuminate\Support\Collection;

class TicketService
{
    public function __construct(
        private TicketRepository $tickets,
        private IssueRepository $issues,
        private EventRepository $events,
        private WorkflowService $wf,
        private NumberingService $numbers,
        private ContentService $content,
        private UnitOfWork $uow,
        private WatchService $watch,
        private NeedinfoService $needinfo,
    ) {}

    // ---------- queries ----------

    public function get(int $id, int $projectId): ClientTicket
    {
        $t = $this->tickets->find($id);
        if (! $t || $t->project_id !== $projectId) {
            throw new BusinessException('找不到這張客戶單。');
        }

        return $t;
    }

    /** What the given user may do on this ticket right now (drives UI buttons). */
    public function abilities(ClientTicket $t, User $u): array
    {
        $st = $this->wf->status('ticket', $t->status);
        $open = $st->client_tab === 'open' && ! $st->is_terminal;
        $lockedByOther = $this->lockValid($t) && $t->edit_lock_user_id !== $u->id;
        $notDispatched = $t->dispatched_at === null;
        $can = fn (string $p) => $this->wf->can($u, $p, $t->project_id);
        $asClient = $u->actsAsClient();
        $internal = $u->seesInternal();

        return [
            'edit' => $asClient && $can('ticket.edit') && $open && $notDispatched && ! $lockedByOther,
            'edit_is_revision' => $t->accepted_at !== null,
            'cancel' => $asClient && $can('ticket.cancel') && $open && $notDispatched && ! $lockedByOther
                && $this->wf->targetByPermission('ticket', $t->status, 'ticket.cancel') !== null,
            'comment' => $asClient && $can('ticket.comment') && ! $st->is_terminal,
            'close' => $asClient && $can('ticket.close') && $this->wf->targetByPermission('ticket', $t->status, 'ticket.close') !== null,
            'reopen' => $asClient && $can('ticket.reopen') && $this->wf->targetByPermission('ticket', $t->status, 'ticket.reopen') !== null,
            'accept' => $internal && $can('ticket.accept') && ! $lockedByOther && $this->wf->targetByPermission('ticket', $t->status, 'ticket.accept') !== null,
            'reply' => $internal && $can('ticket.reply') && ! $st->is_terminal,
            'settle' => $internal && $can('ticket.settle') && $t->needs_pm_action,
            'locked_by_other' => $lockedByOther ? $t->lockUser?->name : null,
            'chain_locked' => ! $notDispatched,
        ];
    }

    /** Timeline: events (+ revisions) for the side panel. Clients only see ticket events. */
    public function timeline(ClientTicket $t): Collection
    {
        return $this->events->forSubject('ticket', $t->id)->map(fn ($e) => [
            'at' => $e->created_at,
            'type' => $e->event_type,
            'label' => $this->eventLabel($e->event_type, $e->to_status),
            'actor' => $e->actor?->name,
            'note' => $e->payload['note'] ?? null,
        ]);
    }

    // ---------- client actions ----------

    public function create(User $u, int $projectId, array $d, ?string $draft = null): ClientTicket
    {
        $this->wf->authorize($u, 'ticket.create', $projectId);
        if (! $u->actsAsClient()) {
            throw new BusinessException('只有客戶帳號可以開立客戶單。');
        }
        $html = $this->content->clean($d['content_html'] ?? '');
        if ($this->content->isBlank($html)) {
            throw new BusinessException('內容不可空白。');
        }

        return $this->uow->run(function () use ($u, $projectId, $d, $html, $draft) {
            $status = $this->wf->initial('ticket_initial', 'default');
            $t = $this->tickets->create([
                'project_id' => $projectId,
                'ticket_no' => $this->numbers->next($projectId, 'ticket'),
                'ticket_type' => $d['ticket_type'],
                'anomaly_category' => $d['anomaly_category'] ?? null,
                'mes_page_id' => $d['mes_page_id'] ?? null,
                'title' => $d['title'],
                'content_html' => $html,
                'status' => $status,
                'current_rev' => 1,
                'row_version' => 1,
                'reopen_count' => 0,
                'needs_pm_action' => false,
                'created_by' => $u->id,
            ]);
            $this->tickets->addRevision($t, $u->id);
            $this->events->log($projectId, 'ticket', $t->id, 'created', null, $status, $u->id);
            $this->content->bind($html, 'ticket', $t->id, $draft);
            $this->content->clearDraft($u->id, $draft);
            $this->watch->autoWatchTicket($t->id, $u->id);
            $this->watch->notifyTicket($t, "新客戶單 {$t->ticket_no}：{$t->title}", $u->id, $this->events->userIdsWithPermission($projectId, 'ticket.accept'), []);

            return $t;
        });
    }

    public function startEdit(User $u, int $id, int $projectId): ClientTicket
    {
        return $this->uow->run(function () use ($u, $id, $projectId) {
            $t = $this->lockedTicket($id, $projectId);
            if (! $this->abilities($t, $u)['edit']) {
                throw new BusinessException($this->editBlockReason($t, $u));
            }
            $minutes = (int) $this->wf->setting('ticket.edit_lock_minutes', '5');

            return $this->tickets->save($t, ['edit_lock_user_id' => $u->id, 'edit_lock_expires_at' => now()->addMinutes($minutes)]);
        });
    }

    public function heartbeat(User $u, int $id, int $projectId): bool
    {
        return $this->uow->run(function () use ($u, $id, $projectId) {
            $t = $this->lockedTicket($id, $projectId);
            if ($t->edit_lock_user_id !== $u->id) {
                return false;
            }
            $minutes = (int) $this->wf->setting('ticket.edit_lock_minutes', '5');
            $this->tickets->save($t, ['edit_lock_expires_at' => now()->addMinutes($minutes)]);

            return true;
        });
    }

    public function releaseEdit(User $u, int $id, int $projectId): void
    {
        $this->uow->run(function () use ($u, $id, $projectId) {
            $t = $this->lockedTicket($id, $projectId);
            if ($t->edit_lock_user_id === $u->id) {
                $this->tickets->save($t, ['edit_lock_user_id' => null, 'edit_lock_expires_at' => null]);
            }
        });
    }

    /** Save an edit. Before PM acceptance: plain edit. After: new revision (takes effect immediately, PM notified). */
    public function saveEdit(User $u, int $id, int $projectId, array $d, int $rowVersion, ?string $draft = null): ClientTicket
    {
        $html = $this->content->clean($d['content_html'] ?? '');
        if ($this->content->isBlank($html)) {
            throw new BusinessException('內容不可空白。');
        }

        return $this->uow->run(function () use ($u, $id, $projectId, $d, $rowVersion, $html, $draft) {
            $t = $this->lockedTicket($id, $projectId);
            if (! $this->lockValid($t) || $t->edit_lock_user_id !== $u->id) {
                throw new BusinessException('編輯鎖已失效，請重新進入編輯。');
            }
            if ($t->row_version !== $rowVersion) {
                throw new BusinessException('這張單已被其他人修改，請重新整理後再編輯。');
            }
            if (! $this->abilities($t, $u)['edit']) {
                throw new BusinessException($this->editBlockReason($t, $u));
            }
            $isRevision = $t->accepted_at !== null;
            $this->tickets->save($t, [
                'title' => $d['title'],
                'ticket_type' => $d['ticket_type'] ?? $t->ticket_type,
                'anomaly_category' => $d['anomaly_category'] ?? null,
                'mes_page_id' => $d['mes_page_id'] ?? null,
                'content_html' => $html,
                'current_rev' => $t->current_rev + 1,
                'row_version' => $t->row_version + 1,
                'edit_lock_user_id' => null,
                'edit_lock_expires_at' => null,
            ]);
            $this->tickets->addRevision($t, $u->id);
            $this->events->log($projectId, 'ticket', $t->id, $isRevision ? 'revised' : 'edited', null, null, $u->id, ['rev' => $t->current_rev]);
            $this->content->bind($html, 'ticket', $t->id, $draft);
            $this->content->clearDraft($u->id, $draft);
            if ($isRevision) {
                $this->watch->notifyTicket($t, "客戶已修訂 {$t->ticket_no}（rev {$t->current_rev}）", $u->id, [$t->accepted_by], []);
            }
            $this->needinfo->answeredBy($u, $t);

            return $t;
        });
    }

    /** Client cancel (only before dispatch). Issues linked only to this ticket and still in PM stage are cancelled; shared ones are unlinked. */
    public function cancel(User $u, int $id, int $projectId, ?string $reason): ClientTicket
    {
        return $this->uow->run(function () use ($u, $id, $projectId, $reason) {
            $t = $this->lockedTicket($id, $projectId);
            if (! $this->abilities($t, $u)['cancel']) {
                throw new BusinessException($t->dispatched_at ? '問題已拋單處理中，不能取消。' : '目前狀態不能取消。');
            }
            $to = $this->wf->targetByPermission('ticket', $t->status, 'ticket.cancel');
            $this->wf->assertTransition('ticket', $t->status, $to, $u, $projectId, ['cancel_reason' => $reason ?: '客戶取消']);
            $from = $t->status;
            $this->tickets->save($t, ['status' => $to, 'cancelled_at' => now(), 'cancel_reason' => $reason, 'row_version' => $t->row_version + 1]);
            $this->events->log($projectId, 'ticket', $t->id, 'cancelled', $from, $to, $u->id, ['note' => $reason]);

            foreach ($this->issues->issuesOfTicket($t->id) as $issue) {
                $stage = $this->wf->status('issue', $issue->status)->stage;
                if ($this->tickets->linkCountOfIssue($issue->id) > 1 || $stage !== $this->pmStage()) {
                    $this->tickets->unlink($t->id, $issue->id);
                    $this->events->log($projectId, 'issue', $issue->id, 'unlinked', null, null, $u->id, ['ticket' => $t->ticket_no]);

                    continue;
                }
                $target = $this->wf->targetByPermission('issue', $issue->status, 'issue.cancel', fn ($s) => $s->is_excluded);
                if ($target) {
                    $prev = $issue->status;
                    $this->issues->save($issue, ['status' => $target, 'stage' => $this->wf->status('issue', $target)->stage ?? $issue->stage, 'cancel_reason' => '客戶取消客戶單']);
                    $this->events->log($projectId, 'issue', $issue->id, 'status', $prev, $target, $u->id, ['note' => '客戶取消客戶單']);
                }
            }
            if ($t->accepted_by) {
                $this->watch->notifyTicket($t, "客戶已取消 {$t->ticket_no}", $u->id, [$t->accepted_by], []);
            }

            return $t;
        });
    }

    public function addComment(User $u, int $id, int $projectId, string $kind, string $html, ?string $draft = null): void
    {
        $html = $this->content->clean($html);
        if ($this->content->isBlank($html)) {
            throw new BusinessException('留言內容不可空白。');
        }
        $this->uow->run(function () use ($u, $id, $projectId, $kind, $html, $draft) {
            $t = $this->get($id, $projectId);
            $ab = $this->abilities($t, $u);
            if (! ($u->isClient() ? $ab['comment'] : $ab['reply'] || ($u->is_admin && $ab['comment']))) {
                throw new BusinessException('目前不能留言。');
            }
            if ($this->wf->lookups('comment_kind')->where('code', $kind)->isEmpty()) {
                throw new BusinessException('留言類型不正確。');
            }
            if ($u->isClient() && in_array($kind, $this->wf->lookupCodes('comment_kind_internal'), true)) {
                throw new BusinessException('留言類型不正確。');
            }
            $c = $this->tickets->addComment($t->id, $kind, $html, $u->id);
            $this->events->log($projectId, 'ticket', $t->id, 'comment', null, null, $u->id, ['kind' => $kind]);
            $this->content->bind($html, 'ticket_comment', $c->id, $draft);
            $this->content->clearDraft($u->id, $draft);
            $title = "{$t->ticket_no} 有新留言";
            if ($u->isClient()) {
                $ids = $this->events->userIdsWithPermission($projectId, 'ticket.accept');
                foreach ($this->issues->issuesOfTicket($t->id) as $i) {
                    $ids[] = $i->assignee_dev_id;
                    $ids[] = $i->assignee_test_id;
                }
                $this->watch->notifyTicket($t, $title, $u->id, $ids, []);
                $this->needinfo->answeredBy($u, $t);
            } else {
                $this->watch->notifyTicket($t, $title, $u->id, [], [$t->created_by]);
            }
        });
    }

    public function markRead(User $u, int $id, int $projectId): void
    {
        $t = $this->get($id, $projectId);
        $this->tickets->markCommentsRead($t->id, $u->id);
    }

    public function close(User $u, int $id, int $projectId): ClientTicket
    {
        return $this->uow->run(function () use ($u, $id, $projectId) {
            $t = $this->lockedTicket($id, $projectId);
            $to = $this->wf->targetByPermission('ticket', $t->status, 'ticket.close') ?? throw new BusinessException('目前狀態不能結案。');
            $this->wf->assertTransition('ticket', $t->status, $to, $u, $projectId);
            $from = $t->status;
            $this->tickets->save($t, ['status' => $to, 'closed_at' => now(), 'row_version' => $t->row_version + 1]);
            $this->events->log($projectId, 'ticket', $t->id, 'closed', $from, $to, $u->id);
            // PM side must learn the client confirmed the fix (acceptor + settlers + watchers).
            $this->watch->notifyTicket($t, "客戶已結案 {$t->ticket_no}", $u->id,
                array_merge([$t->accepted_by], $this->events->userIdsWithPermission($projectId, 'ticket.settle')), []);

            return $t;
        });
    }

    /** Client reports the problem persists. PM then opens a NEW internal issue linked to the ticket. */
    public function reopen(User $u, int $id, int $projectId, string $html, ?string $draft = null): ClientTicket
    {
        $html = $this->content->clean($html);
        if ($this->content->isBlank($html)) {
            throw new BusinessException('請說明仍有異常的內容。');
        }

        return $this->uow->run(function () use ($u, $id, $projectId, $html, $draft) {
            $t = $this->lockedTicket($id, $projectId);
            $to = $this->wf->targetByPermission('ticket', $t->status, 'ticket.reopen') ?? throw new BusinessException('目前狀態不能提出異常。');
            $this->wf->assertTransition('ticket', $t->status, $to, $u, $projectId);
            $from = $t->status;
            $round = $t->reopen_count + 1;
            $r = $this->tickets->addReopen($t->id, $round, $html, $u->id);
            $this->tickets->save($t, ['status' => $to, 'reopen_count' => $round, 'needs_pm_action' => true, 'row_version' => $t->row_version + 1]);
            $this->events->log($projectId, 'ticket', $t->id, 'reopened', $from, $to, $u->id, ['round' => $round]);
            $this->content->bind($html, 'ticket', $t->id, $draft);
            $this->content->clearDraft($u->id, $draft);
            $this->watch->notifyTicket($t, "客戶提出異常 {$t->ticket_no}（第 {$round} 次）", $u->id,
                array_merge([$t->accepted_by], $this->events->userIdsWithPermission($projectId, 'ticket.settle')), []);

            return $t;
        });
    }

    // ---------- PM actions ----------

    public function accept(User $u, int $id, int $projectId): ClientTicket
    {
        return $this->uow->run(function () use ($u, $id, $projectId) {
            $t = $this->lockedTicket($id, $projectId);
            if ($this->lockValid($t)) {
                throw new BusinessException("客戶正在編輯（{$t->lockUser?->name}），暫時不能接收。");
            }
            $to = $this->wf->targetByPermission('ticket', $t->status, 'ticket.accept') ?? throw new BusinessException('這張單已被接收。');
            $this->wf->assertTransition('ticket', $t->status, $to, $u, $projectId);
            $from = $t->status;
            $this->tickets->save($t, ['status' => $to, 'accepted_by' => $u->id, 'accepted_at' => now(), 'needs_pm_action' => true, 'row_version' => $t->row_version + 1]);
            $this->events->log($projectId, 'ticket', $t->id, 'accepted', $from, $to, $u->id);
            $this->watch->autoWatchTicket($t->id, $u->id);
            $this->watch->notifyTicket($t, "PM 已接收 {$t->ticket_no}", $u->id, [], [$t->created_by]);

            return $t;
        });
    }

    public function ackRevisions(User $u, int $id, int $projectId): void
    {
        $t = $this->get($id, $projectId);
        $this->wf->authorize($u, 'ticket.accept', $projectId);
        $this->tickets->ackRevisions($t->id, $u->id);
    }

    /**
     * PM disposal when needs_pm_action is set and no new issue will be created:
     * $action = 'pending' (to pending close) | 'cancel'. Note is shown to the client.
     */
    public function settle(User $u, int $id, int $projectId, string $action, string $note): ClientTicket
    {
        if (trim($note) === '') {
            throw new BusinessException('請填寫給客戶的說明。');
        }

        return $this->uow->run(function () use ($u, $id, $projectId, $action, $note) {
            $t = $this->lockedTicket($id, $projectId);
            $this->wf->authorize($u, 'ticket.settle', $projectId);
            if (! $t->needs_pm_action) {
                throw new BusinessException('這張單不需要處置。');
            }
            $targetTab = $this->wf->lookupValue('settle_tab', $action);
            $filter = fn ($s) => $s->client_tab === $targetTab;
            $to = $this->wf->targetByPermission('ticket', $t->status, 'ticket.settle', $filter) ?? throw new BusinessException('目前狀態不能這樣處置。');
            $this->wf->assertTransition('ticket', $t->status, $to, $u, $projectId, ['cancel_reason' => $note]);
            $from = $t->status;
            $extra = $action === 'cancel' ? ['cancelled_at' => now(), 'cancel_reason' => $note] : [];
            $this->tickets->save($t, ['status' => $to, 'needs_pm_action' => false, 'row_version' => $t->row_version + 1] + $extra);
            $this->tickets->addComment($t->id, $this->internalReplyKind(), '<p>'.e($note).'</p>', $u->id);
            $this->events->log($projectId, 'ticket', $t->id, 'settled', $from, $to, $u->id, ['note' => $note]);
            $this->watch->notifyTicket($t, "{$t->ticket_no} 狀態更新：".$this->wf->label('ticket', $to), $u->id, [], [$t->created_by]);

            return $t;
        });
    }

    // ---------- helpers ----------

    private function lockedTicket(int $id, int $projectId): ClientTicket
    {
        $t = $this->tickets->findForUpdate($id);
        if (! $t || $t->project_id !== $projectId) {
            throw new BusinessException('找不到這張客戶單。');
        }

        return $t;
    }

    public function lockValid(ClientTicket $t): bool
    {
        return $t->edit_lock_user_id !== null && $t->edit_lock_expires_at !== null && $t->edit_lock_expires_at->isFuture();
    }

    private function editBlockReason(ClientTicket $t, User $u): string
    {
        if ($t->dispatched_at) {
            return '問題已拋單給 DEV/Test，內容已鎖定，只能補充留言。';
        }
        if ($this->lockValid($t) && $t->edit_lock_user_id !== $u->id) {
            return "{$t->lockUser?->name} 正在編輯這張單。";
        }

        return '目前狀態不能編輯。';
    }

    private function pmStage(): ?string
    {
        return $this->wf->status('issue', $this->wf->initial('issue_initial', 'pm'))->stage;
    }

    /** Comment kind used for internal (PM) replies: first code of lookup comment_kind_internal. */
    public function internalReplyKind(): string
    {
        return $this->wf->lookupCodes('comment_kind_internal')[0] ?? throw new BusinessException('缺少設定：comment_kind_internal');
    }


    private function eventLabel(string $type, ?string $to): string
    {
        $map = [
            'created' => '開立', 'edited' => '編輯', 'revised' => '提出修訂', 'accepted' => 'PM 接收',
            'cancelled' => '取消', 'closed' => '結案', 'reopened' => '提出異常', 'comment' => '留言',
            'settled' => 'PM 處置', 'dispatched' => '已拋單處理', 'pending_close' => '待結案',
            'linked' => '建立內部處理單',
        ];
        $label = $map[$type] ?? $this->wf->lookupLabel('event_label', $type);

        return $to ? $label.'（'.$this->wf->label('ticket', $to).'）' : $label;
    }
}
