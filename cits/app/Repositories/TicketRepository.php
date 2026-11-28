<?php

namespace App\Repositories;

use App\Models\ClientTicket;
use App\Models\TicketComment;
use App\Models\TicketReopen;
use App\Models\TicketRevision;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class TicketRepository
{
    /** Whitelisted sort keys -> columns. */
    private const SORTS = ['submitted' => 'created_at', 'last' => 'updated_at', 'no' => 'ticket_no'];

    public function find(int $id): ?ClientTicket
    {
        return ClientTicket::find($id);
    }

    public function findForUpdate(int $id): ?ClientTicket
    {
        return ClientTicket::whereKey($id)->lockForUpdate()->first();
    }

    /** @param int[] $ids @return Collection<int, ClientTicket> locked, ordered by id (fixed lock order) */
    public function lockMany(array $ids): Collection
    {
        sort($ids);

        return ClientTicket::whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get();
    }

    public function create(array $data): ClientTicket
    {
        return ClientTicket::create($data);
    }

    public function save(ClientTicket $t, array $data): ClientTicket
    {
        $t->fill($data)->save();

        return $t;
    }

    /**
     * @param  string[]  $statuses
     * @param  array{type?:string,q?:string,sort?:string,dir?:string,mine?:int}  $f
     */
    private function filtered(int $projectId, array $statuses, array $f): Builder
    {
        $sort = self::SORTS[$f['sort'] ?? 'last'] ?? 'updated_at';
        $dir = ($f['dir'] ?? 'desc') === 'asc' ? 'asc' : 'desc';

        return ClientTicket::query()
            ->where('project_id', $projectId)
            ->when($statuses, fn ($q) => $q->whereIn('status', $statuses))
            ->when($f['type'] ?? null, fn ($q, $v) => $q->where('ticket_type', $v))
            ->when($f['mine'] ?? null, fn ($q, $v) => $q->where('created_by', $v))
            ->when(isset($f['ids']), fn ($q) => $q->whereIn('id', $f['ids'] ?: [0]))
            ->when($f['q'] ?? null, fn ($q, $v) => $q->where(fn ($w) => $w->where('title', 'like', "%$v%")->orWhere('ticket_no', 'like', "%$v%")))
            ->orderBy($sort, $dir)->orderByDesc('id');
    }

    /**
     * @param  string[]  $statuses
     * @param  array{type?:string,q?:string,sort?:string,dir?:string,mine?:int}  $f
     */
    public function paginate(int $projectId, array $statuses, array $f, int $perPage = 20): LengthAwarePaginator
    {
        return $this->filtered($projectId, $statuses, $f)
            ->with(['creator:id,name,account', 'mesPage:id,name'])
            ->paginate($perPage)->withQueryString();
    }

    /**
     * Same filter/order as paginate(), not paginated, with relations needed by the export.
     *
     * @param  string[]  $statuses
     * @return Collection<int, ClientTicket>
     */
    public function listFiltered(int $projectId, array $statuses, array $f, int $limit): Collection
    {
        return $this->filtered($projectId, $statuses, $f)
            ->with(['creator:id,name', 'mesPage:id,name', 'comments.author:id,name'])
            ->limit($limit)->get();
    }

    /** @return array<string,int> status => count */
    public function countByStatus(int $projectId): array
    {
        return ClientTicket::where('project_id', $projectId)->groupBy('status')
            ->selectRaw('status, count(*) as c')->pluck('c', 'status')->all();
    }

    public function needsPmActionCount(int $projectId): int
    {
        return ClientTicket::where('project_id', $projectId)->where('needs_pm_action', true)->count();
    }

    /** @return Collection<int, ClientTicket> */
    public function ticketsOfIssue(int $issueId, bool $lock = false): Collection
    {
        $links = DB::table('ticket_issue_links')->where('issue_id', $issueId);
        $ids = ($lock ? $links->sharedLock() : $links)->pluck('ticket_id')->all();

        return $lock ? $this->lockMany($ids) : ClientTicket::whereIn('id', $ids)->orderBy('id')->get();
    }

    public function link(int $ticketId, int $issueId, int $rev, int $userId): void
    {
        DB::table('ticket_issue_links')->insertOrIgnore([
            'ticket_id' => $ticketId, 'issue_id' => $issueId, 'based_on_rev' => $rev,
            'linked_by' => $userId, 'linked_at' => now(),
        ]);
    }

    public function unlink(int $ticketId, int $issueId): void
    {
        DB::table('ticket_issue_links')->where(['ticket_id' => $ticketId, 'issue_id' => $issueId])->delete();
    }

    /**
     * Status of every internal issue linked to the given tickets (client progress; no internal numbers leave the server).
     *
     * @param  int[]  $ticketIds
     * @return Collection<int, object{ticket_id:int,stage:?string,status:string,release_planned_at:?string}>
     */
    public function linkedIssueStates(array $ticketIds): Collection
    {
        return DB::table('ticket_issue_links as l')->join('issues as i', 'i.id', '=', 'l.issue_id')
            ->whereIn('l.ticket_id', $ticketIds ?: [0])->whereNull('i.deleted_at')
            ->get(['l.ticket_id', 'i.stage', 'i.status', 'i.close_from', 'i.release_planned_at']);
    }

    public function linkCountOfIssue(int $issueId): int
    {
        return DB::table('ticket_issue_links')->where('issue_id', $issueId)->count();
    }

    public function addRevision(ClientTicket $t, int $userId): TicketRevision
    {
        return TicketRevision::create([
            'ticket_id' => $t->id, 'rev_no' => $t->current_rev, 'title' => $t->title,
            'content_snapshot' => $t->content_html, 'created_by' => $userId, 'created_at' => now(),
        ]);
    }

    public function ackRevisions(int $ticketId, int $userId): int
    {
        return TicketRevision::where('ticket_id', $ticketId)->whereNull('pm_ack_at')->where('rev_no', '>', 1)
            ->update(['pm_ack_by' => $userId, 'pm_ack_at' => now()]);
    }

    public function addReopen(int $ticketId, int $round, string $html, int $userId): TicketReopen
    {
        return TicketReopen::create(['ticket_id' => $ticketId, 'round' => $round, 'content_html' => $html, 'created_by' => $userId, 'created_at' => now()]);
    }

    public function addComment(int $ticketId, string $kind, string $html, int $userId): TicketComment
    {
        return TicketComment::create(['ticket_id' => $ticketId, 'kind' => $kind, 'content_html' => $html, 'created_by' => $userId, 'created_at' => now()]);
    }

    public function markCommentsRead(int $ticketId, int $userId): void
    {
        $ids = TicketComment::where('ticket_id', $ticketId)->where('created_by', '!=', $userId)->pluck('id');
        $now = now();
        DB::table('comment_reads')->insertOrIgnore($ids->map(fn ($id) => ['comment_id' => $id, 'user_id' => $userId, 'read_at' => $now])->all());
    }

    /** @return array<int, string[]> comment_id => reader names */
    public function readersOf(int $ticketId): array
    {
        return DB::table('comment_reads as r')->join('ticket_comments as c', 'c.id', '=', 'r.comment_id')
            ->join('users as u', 'u.id', '=', 'r.user_id')->where('c.ticket_id', $ticketId)
            ->get(['r.comment_id', 'u.name', 'u.user_type'])
            ->groupBy('comment_id')->map(fn ($g) => $g->map(fn ($r) => $r->name.($r->user_type === 'internal' ? '（內部）' : ''))->all())->all();
    }
}
