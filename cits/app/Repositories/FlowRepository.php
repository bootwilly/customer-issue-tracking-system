<?php

namespace App\Repositories;

use App\Models\ProjectFlow;
use App\Models\ProjectFlowDraftSnapshot;
use App\Models\ProjectFlowEditor;
use App\Models\ProjectFlowRevision;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class FlowRepository
{
    private const LIST_COLUMNS = ['id', 'project_id', 'title', 'format', 'status', 'version', 'sort', 'updated_by', 'updated_at',
        'draft_title', 'draft_rev', 'draft_updated_by', 'draft_updated_at', 'deleted_at', 'deleted_by'];

    /**
     * Live (not deleted) flows of a project.
     *
     * @param  string[]|null  $statuses  null = every status
     * @param  int[]  $alsoIds  flows always included (the user's own editor assignments)
     * @return Collection<int, ProjectFlow>
     */
    public function list(int $projectId, ?array $statuses, array $alsoIds = [], ?string $keyword = null): Collection
    {
        $like = $keyword !== null && $keyword !== '' ? '%'.addcslashes($keyword, '\%_').'%' : null;

        return ProjectFlow::with(['editor:id,name', 'draftEditor:id,name'])
            ->select(self::LIST_COLUMNS)
            ->selectRaw('CASE WHEN draft_source IS NULL THEN 0 ELSE 1 END AS has_draft')
            ->where('project_id', $projectId)->whereNull('deleted_at')
            ->when($statuses !== null, fn ($q) => $q->where(fn ($w) => $w->whereIn('status', $statuses)->when($alsoIds, fn ($o) => $o->orWhereIn('id', $alsoIds))))
            ->when($like, fn ($q) => $q->where(fn ($w) => $w->where('title', 'like', $like)->orWhere('search_text', 'like', $like)->orWhere('draft_title', 'like', $like)))
            ->orderBy('sort')->orderBy('id')
            ->get();
    }

    /** @return Collection<int, ProjectFlow> soft-deleted flows of a project, newest deletion first */
    public function trashed(int $projectId): Collection
    {
        return ProjectFlow::with('deleter:id,name')->select(self::LIST_COLUMNS)
            ->where('project_id', $projectId)->whereNotNull('deleted_at')
            ->orderByDesc('deleted_at')->get();
    }

    public function find(int $id): ?ProjectFlow
    {
        return ProjectFlow::with(['editor:id,name', 'draftEditor:id,name'])->whereNull('deleted_at')->find($id);
    }

    public function findTrashed(int $id): ?ProjectFlow
    {
        return ProjectFlow::whereNotNull('deleted_at')->find($id);
    }

    /** Row lock (caller runs inside UnitOfWork). */
    public function findForUpdate(int $id): ?ProjectFlow
    {
        return ProjectFlow::whereKey($id)->whereNull('deleted_at')->lockForUpdate()->first();
    }

    public function create(array $data): ProjectFlow
    {
        return ProjectFlow::create($data);
    }

    public function save(ProjectFlow $f, array $data): ProjectFlow
    {
        $f->fill($data)->save();

        return $f;
    }

    /** Hard delete; revisions, editors and snapshots go by FK cascade. */
    public function purge(ProjectFlow $f): void
    {
        $f->delete();
    }

    public function revisionCount(int $flowId): int
    {
        return ProjectFlowRevision::where('flow_id', $flowId)->count();
    }

    public function addRevision(array $data): ProjectFlowRevision
    {
        return ProjectFlowRevision::create($data);
    }

    /** @return Collection<int, ProjectFlowRevision> newest first */
    public function revisions(int $flowId): Collection
    {
        return ProjectFlowRevision::with('author:id,name')->where('flow_id', $flowId)
            ->orderByDesc('version')->get(['id', 'flow_id', 'version', 'title', 'note', 'format', 'created_by', 'created_at']);
    }

    public function revision(int $flowId, int $version): ?ProjectFlowRevision
    {
        return ProjectFlowRevision::with('author:id,name')->where(['flow_id' => $flowId, 'version' => $version])->first();
    }

    // ---- editors ----

    /** Assigned editor who is still a member of the flow's project. */
    public function isEditor(int $flowId, int $projectId, int $userId): bool
    {
        return DB::table('project_flow_editors as e')
            ->join('project_members as m', fn ($j) => $j->on('m.user_id', '=', 'e.user_id')->where('m.project_id', $projectId))
            ->where(['e.flow_id' => $flowId, 'e.user_id' => $userId])->exists();
    }

    /** @return int[] flow ids of a project the user is an assigned editor of (membership checked) */
    public function editorFlowIds(int $projectId, int $userId): array
    {
        return DB::table('project_flow_editors as e')
            ->join('project_flows as f', 'f.id', '=', 'e.flow_id')
            ->join('project_members as m', fn ($j) => $j->on('m.user_id', '=', 'e.user_id')->where('m.project_id', $projectId))
            ->where(['f.project_id' => $projectId, 'e.user_id' => $userId])->whereNull('f.deleted_at')
            ->pluck('e.flow_id')->map(fn ($v) => (int) $v)->all();
    }

    /** @return Collection<int, ProjectFlowEditor> */
    public function editors(int $flowId): Collection
    {
        return ProjectFlowEditor::with('user:id,name,account,user_type')->where('flow_id', $flowId)->orderBy('id')->get();
    }

    /** Replace the editor list. @param int[] $userIds */
    public function syncEditors(int $flowId, array $userIds, int $by): void
    {
        ProjectFlowEditor::where('flow_id', $flowId)->whereNotIn('user_id', $userIds ?: [0])->delete();
        $have = ProjectFlowEditor::where('flow_id', $flowId)->pluck('user_id')->all();
        foreach (array_diff($userIds, $have) as $uid) {
            ProjectFlowEditor::create(['flow_id' => $flowId, 'user_id' => $uid, 'created_by' => $by, 'created_at' => now()]);
        }
    }

    // ---- draft snapshots ----

    public function addSnapshot(array $data): ProjectFlowDraftSnapshot
    {
        return ProjectFlowDraftSnapshot::create($data);
    }

    /** Keep only the newest $keep snapshots of a flow. */
    public function pruneSnapshots(int $flowId, int $keep): void
    {
        $ids = ProjectFlowDraftSnapshot::where('flow_id', $flowId)->orderByDesc('id')->skip(max(0, $keep))->take(PHP_INT_MAX)->pluck('id');
        if ($ids->isNotEmpty()) {
            ProjectFlowDraftSnapshot::whereIn('id', $ids)->delete();
        }
    }

    public function clearSnapshots(int $flowId): void
    {
        ProjectFlowDraftSnapshot::where('flow_id', $flowId)->delete();
    }

    /** @return Collection<int, ProjectFlowDraftSnapshot> newest first, without the source */
    public function snapshots(int $flowId): Collection
    {
        return ProjectFlowDraftSnapshot::with('author:id,name')->where('flow_id', $flowId)
            ->orderByDesc('id')->get(['id', 'flow_id', 'draft_rev', 'title', 'created_by', 'created_at']);
    }

    public function snapshot(int $flowId, int $id): ?ProjectFlowDraftSnapshot
    {
        return ProjectFlowDraftSnapshot::where(['flow_id' => $flowId, 'id' => $id])->first();
    }
}
