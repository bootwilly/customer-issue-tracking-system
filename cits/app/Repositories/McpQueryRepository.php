<?php

namespace App\Repositories;

use App\Models\ActivityEvent;
use App\Models\ClientTicket;
use App\Models\Issue;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Collection;

/** Read-only queries for the MCP server. Never writes. */
class McpQueryRepository
{
    /** @return Collection<int, Project> active projects the user may reach (admins: all). */
    public function accessibleProjects(User $user): Collection
    {
        $q = Project::where('status', 'active');
        if (! $user->is_admin) {
            $q->whereIn('id', $user->projects()->select('projects.id'));
        }

        return $q->orderBy('code')->get(['id', 'code', 'name']);
    }

    /**
     * @param  int[]  $projectIds
     * @param  array{status?:?string,stage?:?string,assignee?:?string,keyword?:?string,exclude_statuses?:string[]}  $f
     * @return Collection<int, Issue>
     */
    public function issues(array $projectIds, array $f, int $limit): Collection
    {
        return Issue::with(['dev:id,name', 'tester:id,name', 'project:id,code'])
            ->whereIn('project_id', $projectIds)
            ->when($f['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($f['stage'] ?? null, fn ($q, $v) => $q->where('stage', $v))
            ->when($f['exclude_statuses'] ?? null, fn ($q, $v) => $q->whereNotIn('status', $v))
            ->when($f['assignee'] ?? null, fn ($q, $v) => $q->where(fn ($w) => $w
                ->whereHas('dev', fn ($u) => $u->where('account', $v))
                ->orWhereHas('tester', fn ($u) => $u->where('account', $v))))
            ->when($f['keyword'] ?? null, fn ($q, $v) => $q->where(fn ($w) => $w->where('title', 'like', "%$v%")->orWhere('issue_no', 'like', "%$v%")))
            ->orderByDesc('updated_at')->orderByDesc('id')
            ->limit($limit)->get();
    }

    public function issueByNo(string $issueNo): ?Issue
    {
        return Issue::with(['dev:id,name', 'tester:id,name', 'project:id,code', 'tickets:id,ticket_no,title,status'])
            ->where('issue_no', $issueNo)->first();
    }

    /** @return Collection<int, ActivityEvent> newest first */
    public function recentEvents(string $subjectType, int $subjectId, int $limit): Collection
    {
        return ActivityEvent::with('actor:id,name')
            ->where(['subject_type' => $subjectType, 'subject_id' => $subjectId])
            ->orderByDesc('id')->limit($limit)->get();
    }

    /**
     * @param  int[]  $projectIds
     * @param  array{status?:?string,keyword?:?string}  $f
     * @return Collection<int, ClientTicket>
     */
    public function tickets(array $projectIds, array $f, int $limit): Collection
    {
        return ClientTicket::with('project:id,code')
            ->whereIn('project_id', $projectIds)
            ->when($f['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($f['keyword'] ?? null, fn ($q, $v) => $q->where(fn ($w) => $w->where('title', 'like', "%$v%")->orWhere('ticket_no', 'like', "%$v%")))
            ->orderByDesc('updated_at')->orderByDesc('id')
            ->limit($limit)->get();
    }
}
