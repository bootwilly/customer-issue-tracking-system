<?php

namespace App\Services\Client;

use App\Models\ClientTicket;
use App\Repositories\TicketRepository;
use App\Services\WorkflowService;
use Illuminate\Support\Collection;

/**
 * Client-safe processing progress of a ticket, derived from its linked internal issues.
 * Steps and labels come from lookup client_progress (code = ticket status code, issue stage, or a released issue status code);
 * the client never sees internal issue numbers or internal status names.
 */
class ClientProgressService
{
    public function __construct(private TicketRepository $tickets, private WorkflowService $wf) {}

    /** @return array<string,string> step code => client label, in order */
    public function steps(): array
    {
        return $this->wf->lookupMap('client_progress');
    }

    /**
     * @param  iterable<ClientTicket>  $tickets
     * @return array<int, array{code:string,label:string,index:int,eta:?string}|null> ticket id => progress (null = no progress shown, e.g. cancelled)
     */
    public function forTickets(iterable $tickets): array
    {
        $list = collect($tickets);
        if ($list->isEmpty()) {
            return [];
        }
        $steps = $this->steps();
        $keys = array_keys($steps);
        $issueStatus = $this->wf->statusMap('issue');
        $ticketStatus = $this->wf->statusMap('ticket');
        $pmStage = $issueStatus->get($this->wf->initial('issue_initial', 'pm'))?->stage;
        $links = $this->tickets->linkedIssueStates($list->pluck('id')->all())->groupBy('ticket_id');

        $out = [];
        foreach ($list as $t) {
            $out[$t->id] = $this->one($t, $links->get($t->id, collect()), $steps, $keys, $issueStatus, $ticketStatus, $pmStage);
        }

        return $out;
    }

    public function forTicket(ClientTicket $t): ?array
    {
        return $this->forTickets([$t])[$t->id] ?? null;
    }

    private function one(ClientTicket $t, Collection $rows, array $steps, array $keys, Collection $issueStatus, Collection $ticketStatus, ?string $pmStage): ?array
    {
        $code = null;
        $eta = null;
        if (in_array($t->status, $keys, true)) {
            $code = $t->status; // submitted / waiting for client close / closed: the ticket itself says it
        } elseif ($ticketStatus->get($t->status)?->is_terminal) {
            return null; // e.g. cancelled
        } else {
            $best = null;
            foreach ($rows as $r) {
                $st = $issueStatus->get($r->status);
                if (! $st || $st->is_excluded) {
                    continue; // cancelled / won't fix issues do not drive client progress
                }
                $idx = array_search($st->is_released ? $st->code : $st->stage, $keys, true);
                if ($idx !== false && ($best === null || $idx < $best)) {
                    $best = $idx; // slowest linked issue decides
                }
                if (! $st->is_released && $r->release_planned_at) {
                    $eta = max($eta ?? '', substr((string) $r->release_planned_at, 0, 10));
                }
            }
            $code = $best !== null ? $keys[$best] : $pmStage;
        }
        $index = array_search($code, $keys, true);
        if ($index === false) {
            return null;
        }

        return ['code' => $code, 'label' => $steps[$code], 'index' => $index, 'eta' => $eta];
    }
}
