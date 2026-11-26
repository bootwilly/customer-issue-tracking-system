<?php

namespace App\Services\Collab;

use App\Exceptions\BusinessException;
use App\Models\ClientTicket;
use App\Models\TicketNeedinfo;
use App\Models\User;
use App\Repositories\EventRepository;
use App\Repositories\NeedinfoRepository;
use App\Repositories\SettingRepository;
use App\Repositories\TicketRepository;
use App\Repositories\UnitOfWork;
use App\Services\WorkflowService;
use Illuminate\Support\Collection;

/**
 * needinfo flag: PM asks the client for more information without changing the ticket status
 * (so settlement and the chain lock are untouched). A client comment or revision answers it.
 */
class NeedinfoService
{
    public const NOTE_MAX = 1000;

    private const ANSWERED = 'answered';

    private const WITHDRAWN = 'withdrawn';

    public function __construct(
        private NeedinfoRepository $repo,
        private TicketRepository $tickets,
        private EventRepository $events,
        private WatchService $watch,
        private WorkflowService $wf,
        private SettingRepository $settings,
        private UnitOfWork $uow,
    ) {}

    public function canRequest(User $u, ClientTicket $t): bool
    {
        return $u->seesInternal() && $this->wf->can($u, 'ticket.needinfo', $t->project_id) && ! $this->wf->status('ticket', $t->status)->is_terminal;
    }

    public function open(int $ticketId): ?TicketNeedinfo
    {
        return $this->repo->open($ticketId);
    }

    /** @return Collection<int, TicketNeedinfo> */
    public function history(int $ticketId): Collection
    {
        return $this->repo->history($ticketId);
    }

    /** @param int[] $ticketIds @return array<int,string> ticket id => waiting|answered */
    public function states(array $ticketIds): array
    {
        return $this->repo->stateOf($ticketIds);
    }

    /** @return array{waiting:int, answered:int} counts over open-tab tickets */
    public function counts(int $projectId): array
    {
        return $this->repo->counts($projectId, $this->wf->codesForTab('open'));
    }

    /** @return int[]|null ticket ids for a list filter (null = no filter) */
    public function filterIds(int $projectId, ?string $state): ?array
    {
        return in_array($state, ['waiting', 'answered'], true) ? $this->repo->ticketIds($projectId, $state) : null;
    }

    public function request(User $u, int $ticketId, int $projectId, string $note): TicketNeedinfo
    {
        $note = trim($note);
        if ($note === '' || mb_strlen($note) > self::NOTE_MAX) {
            throw new BusinessException('請填寫要客戶補充的內容（最多 '.self::NOTE_MAX.' 字）。');
        }

        return $this->uow->run(function () use ($u, $ticketId, $projectId, $note) {
            $t = $this->ticket($ticketId, $projectId);
            if (! $this->canRequest($u, $t)) {
                throw new BusinessException('目前不能要求客戶補充資料。');
            }
            if ($this->repo->open($t->id, true)) {
                throw new BusinessException('這張單已經在等待客戶補充，請先取消原本的要求。');
            }
            $n = $this->repo->create(['ticket_id' => $t->id, 'note' => $note, 'requested_by' => $u->id, 'requested_at' => now()]);
            $this->events->log($projectId, 'ticket', $t->id, 'needinfo_requested', null, null, $u->id, ['note' => $note]);
            $this->watch->notifyTicket($t, "{$t->ticket_no} 需要您補充資料", $u->id, [], [$t->created_by]);

            return $n;
        });
    }

    public function withdraw(User $u, int $ticketId, int $projectId): void
    {
        $this->uow->run(function () use ($u, $ticketId, $projectId) {
            $t = $this->ticket($ticketId, $projectId);
            if (! $u->seesInternal() || ! $this->wf->can($u, 'ticket.needinfo', $projectId)) {
                throw new BusinessException('沒有權限執行此操作。');
            }
            $n = $this->repo->open($t->id, true) ?? throw new BusinessException('這張單沒有等待中的補充要求。');
            $this->repo->save($n, ['resolved_at' => now(), 'resolved_by' => $u->id, 'resolution' => self::WITHDRAWN]);
            $this->events->log($projectId, 'ticket', $t->id, 'needinfo_withdrawn', null, null, $u->id);
        });
    }

    /** Called by TicketService inside its transaction when the client comments or revises. */
    public function answeredBy(User $u, ClientTicket $t): void
    {
        if (! $u->isClient()) {
            return; // only a client account answers (an internal admin writing on the client page does not)
        }
        $n = $this->repo->open($t->id, true);
        if (! $n) {
            return;
        }
        $this->repo->save($n, ['resolved_at' => now(), 'resolved_by' => $u->id, 'resolution' => self::ANSWERED]);
        $this->events->log($t->project_id, 'ticket', $t->id, 'needinfo_answered', null, null, $u->id);
        $this->watch->notifyTicket($t, "{$t->ticket_no} 客戶已補充資料", $u->id, [$n->requested_by], []);
    }

    /** PM opened the ticket: answered requests stop showing as "client answered". */
    public function markSeen(User $u, ClientTicket $t): void
    {
        if ($u->seesInternal() && $this->wf->can($u, 'ticket.needinfo', $t->project_id)) {
            $this->repo->markSeen($t->id);
        }
    }

    /** Daily reminder: open requests older than needinfo.remind_days remind the client and the requesting PM once. */
    public function remind(): int
    {
        $days = max(1, $this->settings->int('needinfo.remind_days', 3));
        $n = 0;
        foreach ($this->repo->dueForReminder(now()->subDays($days)) as $row) {
            $t = $row->ticket;
            if (! $t || $this->wf->status('ticket', $t->status)->is_terminal) {
                $this->repo->save($row, ['reminded_at' => now()]);

                continue;
            }
            $this->watch->notifyTicket($t, "{$t->ticket_no} 仍在等待客戶補充資料（已 {$days} 天）", null, [$row->requested_by], [$t->created_by]);
            $this->repo->save($row, ['reminded_at' => now()]);
            $n++;
        }

        return $n;
    }

    private function ticket(int $id, int $projectId): ClientTicket
    {
        $t = $this->tickets->findForUpdate($id);
        if (! $t || $t->project_id !== $projectId) {
            throw new BusinessException('找不到這張客戶單。');
        }

        return $t;
    }
}
