<?php

namespace App\Repositories;

use App\Models\ClientTicket;
use App\Models\DueChangeRequest;
use App\Models\Issue;
use App\Models\IssueAddendum;
use App\Models\IssueComment;
use App\Models\IssueDependency;
use App\Models\IssueImportRow;
use App\Models\Package;
use App\Models\TicketComment;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class IssueRepository
{
    private const SORTS = [
        'created' => 'created_at', 'last' => 'updated_at', 'due' => 'current_due', 'severity' => 'severity',
        'test_date' => 'test_scheduled_at', 'release_date' => 'release_planned_at', 'no' => 'issue_no',
    ];

    private const EXPORT_RELATIONS = [
        'dev:id,name', 'tester:id,name', 'creator:id,name', 'mesPage:id,name', 'package:id,name',
        'tickets:id,ticket_no,title,status', 'comments.author:id,name', 'addenda.author:id,name',
    ];

    public function find(int $id): ?Issue
    {
        return Issue::find($id);
    }

    public function findForUpdate(int $id): ?Issue
    {
        return Issue::whereKey($id)->lockForUpdate()->first();
    }

    /**
     * Live issues locked for a batch, in id order (fixed lock order).
     *
     * @param  int[]  $ids
     * @return EloquentCollection<int, Issue>
     */
    public function lockMany(array $ids): EloquentCollection
    {
        return Issue::whereIn('id', $ids ?: [0])->orderBy('id')->lockForUpdate()->get();
    }

    // ---- recycle bin ----

    /** @return EloquentCollection<int, Issue> deleted issues of a project, newest deletion first */
    public function trashed(int $projectId): EloquentCollection
    {
        return Issue::onlyTrashed()->with(['deleter:id,name', 'creator:id,name', 'tickets:id,ticket_no'])
            ->where('project_id', $projectId)->orderByDesc('deleted_at')->orderByDesc('id')->get();
    }

    /** A deleted issue (with who deleted it), or null when the issue is live or gone. */
    public function findTrashed(int $id): ?Issue
    {
        return Issue::onlyTrashed()->with('deleter:id,name')->find($id);
    }

    public function lockTrashed(int $id): ?Issue
    {
        return Issue::onlyTrashed()->whereKey($id)->lockForUpdate()->first();
    }

    public function moveToTrash(Issue $i, int $userId): void
    {
        $i->fill(['deleted_at' => now(), 'deleted_by' => $userId])->save();
    }

    public function restoreFromTrash(Issue $i): void
    {
        $i->fill(['deleted_at' => null, 'deleted_by' => null])->save();
    }

    /** @return int[] */
    public function commentIds(int $issueId): array
    {
        return IssueComment::where('issue_id', $issueId)->pluck('id')->all();
    }

    /** Permanent delete: every row that references the issue, then the issue (meeting_items.issue_id is set null by its FK). */
    public function purge(Issue $i): void
    {
        $i->closeTasks()->delete();
        IssueAddendum::where('issue_id', $i->id)->delete(); // before comments: an addendum may quote a comment
        IssueComment::where('issue_id', $i->id)->delete();
        DueChangeRequest::where('issue_id', $i->id)->delete();
        IssueDependency::where('issue_id', $i->id)->orWhere('depends_on_id', $i->id)->delete();
        IssueImportRow::where('issue_id', $i->id)->delete();
        DB::table('issue_watchers')->where('issue_id', $i->id)->delete();
        DB::table('issue_tags')->where('issue_id', $i->id)->delete();
        DB::table('ticket_issue_links')->where('issue_id', $i->id)->delete();
        $i->forceDelete();
    }

    public function create(array $data): Issue
    {
        return Issue::create($data);
    }

    public function save(Issue $i, array $data): Issue
    {
        $i->fill($data)->save();

        return $i;
    }

    /** Project-scoped creation evidence, one row per issue even after later links or discussions. */
    private function classified(int $projectId, array $externalOrigins): Builder
    {
        $eventId = DB::table('events as e')->selectRaw('MAX(e.id)')
            ->whereColumn('e.subject_id', 'issues.id')->where('e.project_id', $projectId)
            ->where('e.subject_type', 'issue')->whereIn('e.event_type', ['created', 'from_meeting', 'imported']);
        $importId = DB::table('issue_import_rows as r')->selectRaw('MIN(r.id)')
            ->whereColumn('r.issue_id', 'issues.id')->where('r.project_id', $projectId);
        $external = implode(', ', array_fill(0, count($externalOrigins), '?')) ?: 'NULL';
        $query = DB::table('issues')->where('issues.project_id', $projectId)->whereNull('issues.deleted_at')
            ->leftJoin('events as evidence', fn ($j) => $j->where('evidence.id', '=', $eventId))
            ->leftJoin('issue_import_rows as imported', fn ($j) => $j->where('imported.id', '=', $importId))
            ->select('issues.*', 'evidence.payload as source_payload', 'imported.source as import_source', 'imported.source_no as import_no');
        $kind = $query->getGrammar()->wrap('evidence.payload->provenance');
        $query->selectRaw("CASE WHEN evidence.event_type = 'from_meeting' THEN 'meeting'
            WHEN evidence.event_type = 'imported' OR imported.id IS NOT NULL THEN COALESCE($kind, 'unknown_import')
            WHEN issues.origin IN ($external) THEN 'ticket' ELSE 'internal' END AS provenance", $externalOrigins);

        return Issue::query()->fromSub($query, 'issues')->withCasts(['source_payload' => 'array']);
    }

    public function provenanceCounts(int $projectId, array $filters): array
    {
        unset($filters['provenance']);

        return $this->filtered($projectId, $filters)->reorder()->groupBy('provenance')
            ->selectRaw('provenance, count(*) as total')->pluck('total', 'provenance')->all();
    }

    public function meetingItemsForSources(int $projectId, array $issueIds, array $itemIds): Collection
    {
        return DB::table('meeting_items as item')->join('meetings as m', 'm.id', '=', 'item.meeting_id')
            ->where('m.project_id', $projectId)->where(fn ($q) => $q->whereIntegerInRaw('item.issue_id', $issueIds)
            ->orWhereIntegerInRaw('item.id', $itemIds))
            ->get(['item.id', 'item.meeting_id', 'item.issue_id']);
    }

    /**
     * Single filter + order definition shared by the list page and the export (exported rows == listed rows).
     *
     * @param  array{stages?:string[],statuses?:string[],origin?:string,dev?:int,test?:int,q?:string,sort?:string,dir?:string,overdue?:bool}  $f
     */
    private function filtered(int $projectId, array $f): Builder
    {
        $sort = self::SORTS[$f['sort'] ?? 'last'] ?? 'updated_at';
        $dir = ($f['dir'] ?? 'desc') === 'asc' ? 'asc' : 'desc';

        $query = isset($f['externalOrigins']) ? $this->classified($projectId, $f['externalOrigins']) : Issue::query();
        $query->where('project_id', $projectId)
            ->when($f['provenance'] ?? null, fn ($q, $v) => $q->where('provenance', $v))
            ->when($f['stages'] ?? null, fn ($q, $v) => $q->whereIn('stage', $v))
            ->when($f['statuses'] ?? null, fn ($q, $v) => $q->whereIn('status', $v))
            ->when($f['origin'] ?? null, fn ($q, $v) => $q->where('origin', $v))
            ->when($f['dev'] ?? null, fn ($q, $v) => $q->where('assignee_dev_id', $v))
            ->when($f['test'] ?? null, fn ($q, $v) => $q->where('assignee_test_id', $v))
            ->when($f['tags'] ?? null, fn ($q, $v) => $q->whereIn('id', fn ($s) => $s->select('issue_id')->from('issue_tags')->whereIn('tag_code', $v)))
            ->when($f['overdue'] ?? false, fn ($q) => $q->whereNotNull('current_due')->whereDate('current_due', '<', now()->toDateString()))
            ->when($f['q'] ?? null, fn ($q, $v) => $q->where(fn ($w) => $w->where('title', 'like', "%$v%")->orWhere('issue_no', 'like', "%$v%")));
        if (($f['sort'] ?? '') === 'status') {
            $rank = DB::table('workflow_statuses')->select('sort')->where('scope', 'issue')->whereColumn('code', 'issues.status');

            return $query->orderBy($rank, $dir)->orderBy('status', $dir)->orderByDesc('id');
        }
        if ($sort === 'severity') {
            $query->orderByRaw('severity IS NULL');
        }

        return $query->orderBy($sort, $dir)->orderByDesc('id');
    }

    public function paginate(int $projectId, array $f, int $perPage = 20): LengthAwarePaginator
    {
        return $this->filtered($projectId, $f)
            ->with(['dev:id,name', 'tester:id,name', 'creator:id,name', 'mesPage:id,name', 'tickets:id,ticket_no,status'])
            ->paginate($perPage)->withQueryString();
    }

    /** Same rows/order as paginate(), up to $limit, with everything the export needs eager-loaded. */
    public function listFiltered(int $projectId, array $f, int $limit): Collection
    {
        return $this->filtered($projectId, $f)->limit($limit)->get()->load(self::EXPORT_RELATIONS);
    }

    /** Eager-load export relations on an already fetched issue collection. */
    public function loadExportRelations(Collection $issues): Collection
    {
        return (new EloquentCollection($issues->all()))->load(self::EXPORT_RELATIONS);
    }

    /** @return Collection<int, Issue> */
    public function issuesOfTicket(int $ticketId): Collection
    {
        // A second issue can finish while this transaction waits for the same ticket.
        // Read the current committed issue states after acquiring the ticket lock.
        return Issue::whereHas('tickets', fn ($q) => $q->where('client_tickets.id', $ticketId))->orderBy('id')->sharedLock()->get();
    }

    public function addComment(int $issueId, string $html, int $userId): IssueComment
    {
        return IssueComment::create(['issue_id' => $issueId, 'content_html' => $html, 'created_by' => $userId, 'created_at' => now()]);
    }

    public function addAddendum(int $issueId, string $html, int $userId, ?int $sourceCommentId = null): IssueAddendum
    {
        return IssueAddendum::create(['issue_id' => $issueId, 'content_html' => $html, 'source_comment_id' => $sourceCommentId, 'created_by' => $userId, 'created_at' => now()]);
    }

    /** A comment of a ticket in the project that is linked to the issue; null otherwise. */
    public function linkedTicketComment(int $issueId, int $ticketId, int $commentId, int $projectId): ?TicketComment
    {
        return TicketComment::query()
            ->whereKey($commentId)
            ->where('ticket_id', $ticketId)
            ->whereIn('ticket_id', ClientTicket::query()->select('id')->where('project_id', $projectId)
                ->whereHas('issues', fn ($q) => $q->where('issues.id', $issueId)))
            ->with('author')
            ->first();
    }

    public function rejectPendingDue(int $issueId, int $reviewer): int
    {
        return DueChangeRequest::where('issue_id', $issueId)->where('status', 'pending')
            ->update(['status' => 'rejected', 'reviewed_by' => $reviewer, 'reviewed_at' => now()]);
    }

    public function createDueRequest(array $data): DueChangeRequest
    {
        return DueChangeRequest::create($data);
    }

    public function findDueRequestForUpdate(int $id): ?DueChangeRequest
    {
        return DueChangeRequest::whereKey($id)->lockForUpdate()->first();
    }

    public function saveDueRequest(DueChangeRequest $r, array $data): void
    {
        $r->fill($data)->save();
    }

    public function pendingDueRequests(int $projectId): Collection
    {
        return DueChangeRequest::with(['issue:id,issue_no,title,project_id', 'requester:id,name'])
            ->where('status', 'pending')->whereHas('issue', fn ($q) => $q->where('project_id', $projectId))
            ->orderBy('id')->get();
    }

    public function createPackage(int $projectId, string $name, int $userId): Package
    {
        return Package::create(['project_id' => $projectId, 'name' => $name, 'built_at' => now(), 'created_by' => $userId]);
    }

    public function findPackageForUpdate(int $id): ?Package
    {
        return Package::whereKey($id)->lockForUpdate()->first();
    }

    public function markPackageReleased(Package $package, \DateTimeInterface $at): void
    {
        $package->fill(['released_at' => $at])->save();
    }

    /** Historical packages may lack their own release timestamp. */
    private function releasedIssue(Builder $query, array $releasedCodes): void
    {
        $query->where(fn (Builder $q) => $q->whereNotNull('released_at')->orWhereIn('status', $releasedCodes));
    }

    public function hasReleasedPackageIssue(Package $package, array $releasedCodes): bool
    {
        $issues = $package->issues()->getQuery()->reorder();
        $this->releasedIssue($issues, $releasedCodes);

        return $issues->exists();
    }

    public function packageWithReleaseState(int $projectId, int $id, array $releasedCodes): ?Package
    {
        return Package::where('project_id', $projectId)->whereKey($id)
            ->withExists(['issues as has_released_issue' => fn (Builder $q) => $this->releasedIssue($q, $releasedCodes)])
            ->first();
    }

    public function packages(int $projectId, array $releasedCodes, bool $openOnly = false): Collection
    {
        $released = fn (Builder $q) => $this->releasedIssue($q, $releasedCodes);
        $query = Package::where('project_id', $projectId)->orderByDesc('id');
        if ($openOnly) {
            $query->whereNull('released_at')->whereDoesntHave('issues', $released);
        } else {
            $query->withExists(['issues as has_released_issue' => $released]);
        }

        return $query->limit(100)->get();
    }

    /**
     * Issues already in the given packages, grouped by package id.
     *
     * @param  int[]  $packageIds
     * @return Collection<int, Collection<int, Issue>>
     */
    public function issuesOfPackages(array $packageIds): Collection
    {
        return Issue::whereIn('package_id', $packageIds ?: [0])->orderBy('issue_no')
            ->get(['id', 'issue_no', 'title', 'status', 'package_id'])->groupBy('package_id');
    }

    /**
     * Issues of a project in the given statuses (package candidates), newest first.
     *
     * @param  string[]  $statuses
     */
    public function packageCandidates(int $projectId, array $statuses, array $releasedCodes): Collection
    {
        return Issue::where('project_id', $projectId)->whereIn('status', $statuses)->whereNull('released_at')
            ->whereNotIn('status', $releasedCodes)
            ->whereDoesntHave('package', fn (Builder $q) => $q->where(fn (Builder $package) => $package->whereNotNull('released_at')
                ->orWhereHas('issues', fn (Builder $issues) => $this->releasedIssue($issues, $releasedCodes))))
            ->with('tester:id,name')->orderByDesc('id')->limit(300)
            ->get(['id', 'issue_no', 'title', 'status', 'package_id', 'assignee_test_id']);
    }
}
