<?php

namespace App\Repositories;

use App\Models\DueChangeRequest;
use App\Models\Issue;
use App\Models\IssueAddendum;
use App\Models\IssueComment;
use App\Models\Package;
use App\Models\TicketComment;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

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

    public function create(array $data): Issue
    {
        return Issue::create($data);
    }

    public function save(Issue $i, array $data): Issue
    {
        $i->fill($data)->save();

        return $i;
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

        return Issue::query()
            ->where('project_id', $projectId)
            ->when($f['stages'] ?? null, fn ($q, $v) => $q->whereIn('stage', $v))
            ->when($f['statuses'] ?? null, fn ($q, $v) => $q->whereIn('status', $v))
            ->when($f['origin'] ?? null, fn ($q, $v) => $q->where('origin', $v))
            ->when($f['dev'] ?? null, fn ($q, $v) => $q->where('assignee_dev_id', $v))
            ->when($f['test'] ?? null, fn ($q, $v) => $q->where('assignee_test_id', $v))
            ->when($f['tags'] ?? null, fn ($q, $v) => $q->whereIn('id', fn ($s) => $s->select('issue_id')->from('issue_tags')->whereIn('tag_code', $v)))
            ->when($f['overdue'] ?? false, fn ($q) => $q->whereNotNull('current_due')->whereDate('current_due', '<', now()->toDateString()))
            ->when($f['q'] ?? null, fn ($q, $v) => $q->where(fn ($w) => $w->where('title', 'like', "%$v%")->orWhere('issue_no', 'like', "%$v%")))
            ->orderBy($sort, $dir)->orderByDesc('id');
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
        return Issue::whereHas('tickets', fn ($q) => $q->where('client_tickets.id', $ticketId))->orderBy('id')->get();
    }

    public function addComment(int $issueId, string $html, int $userId): IssueComment
    {
        return IssueComment::create(['issue_id' => $issueId, 'content_html' => $html, 'created_by' => $userId, 'created_at' => now()]);
    }

    public function addAddendum(int $issueId, string $html, int $userId, ?int $sourceCommentId = null): IssueAddendum
    {
        return IssueAddendum::create(['issue_id' => $issueId, 'content_html' => $html, 'source_comment_id' => $sourceCommentId, 'created_by' => $userId, 'created_at' => now()]);
    }

    public function ticketComment(int $id): ?TicketComment
    {
        return TicketComment::find($id);
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

    public function findPackage(int $id): ?Package
    {
        return Package::find($id);
    }

    public function packages(int $projectId): Collection
    {
        return Package::where('project_id', $projectId)->orderByDesc('id')->limit(100)->get();
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
    public function inStatuses(int $projectId, array $statuses, int $limit = 300): Collection
    {
        return Issue::where('project_id', $projectId)->whereIn('status', $statuses ?: [''])->with('tester:id,name')
            ->orderByDesc('id')->limit($limit)->get(['id', 'issue_no', 'title', 'status', 'package_id', 'assignee_test_id']);
    }
}
