<?php

namespace App\Services\Client;

use App\Models\ClientTicket;
use App\Models\User;
use App\Repositories\ClientMesLookupRepository;
use App\Repositories\ClientTicketDetailRepository;
use App\Repositories\TicketRepository;
use App\Services\TicketService;
use App\Services\WorkflowService;
use Illuminate\Pagination\LengthAwarePaginator;

/** Read-side helper for the client pages (BLL: repositories/services only). */
class ClientQueryService
{
    public function __construct(
        private TicketRepository $tickets,
        private WorkflowService $wf,
        private TicketService $ts,
        private ClientMesLookupRepository $mes,
        private ClientTicketDetailRepository $detail,
    ) {}

    /** @return array<string,string> client_tab code => label (lookup client_tab) */
    public function tabs(): array
    {
        return $this->wf->lookupMap('client_tab');
    }

    /** @return string[] ticket types that require an anomaly category (lookup category_required_type) */
    public function anomalyTypes(): array
    {
        return $this->wf->lookupCodes('category_required_type');
    }

    /** @return array<string,string> comment kinds a client may pick (internal kinds excluded) */
    public function clientCommentKinds(): array
    {
        return array_diff_key($this->commentKinds(), array_flip($this->wf->lookupCodes('comment_kind_internal')));
    }

    /** @return array<string,int> */
    public function tabCounts(int $projectId): array
    {
        $by = $this->tickets->countByStatus($projectId);
        $out = [];
        foreach (array_keys($this->tabs()) as $tab) {
            $out[$tab] = array_sum(array_intersect_key($by, array_flip($this->wf->codesForTab($tab))));
        }

        return $out;
    }

    public function paginate(int $projectId, string $tab, array $filters): LengthAwarePaginator
    {
        return $this->tickets->paginate($projectId, $this->wf->codesForTab($tab), $filters);
    }

    /** @return array<int,array> ticket id => abilities */
    public function abilitiesMap(iterable $tickets, User $u): array
    {
        $out = [];
        foreach ($tickets as $t) {
            $out[$t->id] = $this->ts->abilities($t, $u);
        }

        return $out;
    }

    public function typeOptions(): array
    {
        return $this->wf->lookups('ticket_type')->pluck('name', 'code')->all();
    }

    public function categoryOptions(): array
    {
        return $this->wf->lookups('anomaly_category')->pluck('name', 'code')->all();
    }

    public function commentKinds(): array
    {
        return $this->wf->lookups('comment_kind')->pluck('name', 'code')->all();
    }

    public function pageOptions(int $projectId): array
    {
        return $this->mes->pageOptions($projectId);
    }

    public function tabOf(string $status): ?string
    {
        foreach (array_keys($this->tabs()) as $tab) {
            if (in_array($status, $this->wf->codesForTab($tab), true)) {
                return $tab;
            }
        }

        return null;
    }

    public function detail(ClientTicket $t): array
    {
        $t = $this->detail->load($t);

        return ['ticket' => $t, 'readers' => $this->tickets->readersOf($t->id)];
    }
}
