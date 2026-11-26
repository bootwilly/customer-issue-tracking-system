<?php

namespace App\Services;

use App\Repositories\EventRepository;
use App\Repositories\IssueRepository;
use App\Repositories\TicketRepository;
use App\Services\Collab\WatchService;

/**
 * Recompute client tickets linked to an issue. Call inside the same transaction as the issue change.
 * active = linked issues whose status is_excluded = 0.
 *  - active non-empty and all is_released -> ticket goes to the "system" target (pending close)
 *  - active empty -> needs_pm_action = 1
 */
class SettlementService
{
    public function __construct(
        private TicketRepository $tickets,
        private IssueRepository $issues,
        private EventRepository $events,
        private WorkflowService $wf,
        private WatchService $watch,
    ) {}

    public function recompute(int $issueId, int $actorId): void
    {
        $statuses = $this->wf->statusMap('issue');
        foreach ($this->tickets->ticketsOfIssue($issueId, true) as $t) {
            if ($this->wf->status('ticket', $t->status)->is_terminal) {
                continue;
            }
            $linked = $this->issues->issuesOfTicket($t->id);
            $active = $linked->filter(fn ($i) => ! $statuses[$i->status]->is_excluded);

            if ($active->isEmpty()) {
                if (! $t->needs_pm_action) {
                    $this->tickets->save($t, ['needs_pm_action' => true]);
                    $this->watch->notifyTicket($t, "{$t->ticket_no} 的內部單都已取消，需要 PM 處置", $actorId,
                        $this->events->userIdsWithPermission($t->project_id, 'ticket.settle'), [], internalOnly: true);
                }

                continue;
            }
            if ($active->every(fn ($i) => $statuses[$i->status]->is_released)) {
                $to = $this->wf->targetByPermission('ticket', $t->status, WorkflowService::SYSTEM);
                if (! $to) {
                    continue;
                }
                $from = $t->status;
                $this->tickets->save($t, ['status' => $to, 'needs_pm_action' => false, 'row_version' => $t->row_version + 1]);
                $excluded = $linked->diff($active)->map(fn ($i) => ['no' => $i->issue_no, 'note' => $i->client_note])->values()->all();
                $this->events->log($t->project_id, 'ticket', $t->id, 'pending_close', $from, $to, $actorId, $excluded ? ['excluded' => $excluded] : []);
                $this->watch->notifyTicket($t, "{$t->ticket_no} 已更新至正式環境，請確認是否結案", null, [], [$t->created_by]);
            }
        }
    }
}
