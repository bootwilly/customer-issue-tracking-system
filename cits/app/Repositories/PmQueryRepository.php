<?php

namespace App\Repositories;

use App\Models\ClientTicket;
use App\Models\Issue;
use App\Models\MesPage;
use App\Models\TicketComment;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/** Read-only queries for the PM / issue detail pages. */
class PmQueryRepository
{
    private function unacked(): \Closure
    {
        return fn ($r) => $r->whereNull('pm_ack_at')->where('rev_no', '>', 1);
    }

    private function needsAction(): \Closure
    {
        return fn (Builder $w) => $w->whereNull('accepted_at')
            ->orWhere('needs_pm_action', true)
            ->orWhereHas('revisions', $this->unacked());
    }

    /** @param string[] $statuses */
    private function pmFiltered(int $projectId, array $statuses, bool $onlyAction, ?string $q, ?array $ids = null): Builder
    {
        return ClientTicket::query()
            ->when($ids !== null, fn ($b) => $b->whereIn('id', $ids ?: [0]))
            ->withCount(['revisions as unacked_revisions_count' => $this->unacked()])
            ->where('project_id', $projectId)
            ->whereIn('status', $statuses)
            ->when($onlyAction, fn ($b) => $b->where($this->needsAction()))
            ->when($q, fn ($b, $v) => $b->where(fn ($w) => $w->where('title', 'like', "%$v%")->orWhere('ticket_no', 'like', "%$v%")))
            ->orderByDesc('updated_at')->orderByDesc('id');
    }

    /** @param string[] $statuses */
    public function pmTickets(int $projectId, array $statuses, bool $onlyAction, ?string $q, ?array $ids = null, int $perPage = 10): LengthAwarePaginator
    {
        return $this->pmFiltered($projectId, $statuses, $onlyAction, $q, $ids)
            ->with(['creator:id,name', 'mesPage:id,name'])
            ->paginate($perPage, ['*'], 'tp')->withQueryString();
    }

    /**
     * Same filter/order as pmTickets(), not paginated, with relations needed by the export.
     *
     * @param  string[]  $statuses
     * @return Collection<int, ClientTicket>
     */
    public function pmTicketsForExport(int $projectId, array $statuses, bool $onlyAction, ?string $q, int $limit, ?array $ids = null): Collection
    {
        return $this->pmFiltered($projectId, $statuses, $onlyAction, $q, $ids)
            ->with(['creator:id,name', 'mesPage:id,name', 'acceptor:id,name', 'issues:id,issue_no,title,status', 'comments.author:id,name'])
            ->limit($limit)->get();
    }

    /**
     * @param  string[]  $statuses
     * @return array{unaccepted:int, action:int, revised:int}
     */
    public function ticketCounters(int $projectId, array $statuses): array
    {
        $base = fn () => ClientTicket::where('project_id', $projectId)->whereIn('status', $statuses);

        return [
            'unaccepted' => $base()->whereNull('accepted_at')->count(),
            'action' => $base()->where('needs_pm_action', true)->count(),
            'revised' => $base()->whereHas('revisions', $this->unacked())->count(),
        ];
    }

    public function ticketDetail(int $id): ?ClientTicket
    {
        return ClientTicket::with([
            'creator:id,name,account', 'mesPage:id,name',
            'revisions.author:id,name', 'comments.author:id,name,user_type', 'reopens', 'issues:id,issue_no,title,status',
        ])->find($id);
    }

    /** @param string[] $statuses */
    public function otherAcceptedTickets(int $projectId, array $statuses, int $exceptId): Collection
    {
        return ClientTicket::where('project_id', $projectId)->whereIn('status', $statuses)
            ->whereNotNull('accepted_at')->where('id', '!=', $exceptId)
            ->orderByDesc('id')->limit(200)->get(['id', 'ticket_no', 'title']);
    }

    /** @param string[] $statuses */
    public function linkableIssues(int $projectId, array $statuses, int $ticketId): Collection
    {
        return Issue::where('project_id', $projectId)->whereIn('status', $statuses)
            ->whereDoesntHave('tickets', fn ($q) => $q->where('client_tickets.id', $ticketId))
            ->orderByDesc('id')->limit(200)->get(['id', 'issue_no', 'title']);
    }

    public function mesPages(int $projectId): Collection
    {
        return MesPage::where('project_id', $projectId)->where('is_active', true)->where('node_type', 'page')
            ->orderBy('name')->get(['id', 'name', 'page_path']);
    }

    public function usersByIds(array $ids): Collection
    {
        return $ids ? User::whereIn('id', $ids)->orderBy('name')->get(['id', 'name', 'account']) : collect();
    }

    public function issueDetail(int $id): ?Issue
    {
        return Issue::with([
            'creator:id,name', 'dev:id,name', 'tester:id,name', 'mesPage:id,name', 'package:id,name',
            'closeMeetingItem:id,meeting_id', 'tickets:id,ticket_no,title', 'addenda.author:id,name', 'comments.author:id,name', 'dueRequests.requester:id,name',
        ])->find($id);
    }

    /** Client-channel comments (ticket_comments) of the given tickets, oldest first. */
    public function commentsOfTickets(array $ticketIds): Collection
    {
        return $ticketIds
            ? TicketComment::with('author:id,name,user_type')->whereIn('ticket_id', $ticketIds)->orderBy('id')->get()
            : collect();
    }
}
