<?php

namespace App\Services\Flow;

use App\Exceptions\BusinessException;
use App\Models\ProjectFlow;
use App\Models\User;
use App\Repositories\EventRepository;
use App\Repositories\FlowRepository;
use App\Repositories\ProjectRepository;
use App\Repositories\UnitOfWork;
use App\Services\WorkflowService;
use Illuminate\Support\Collection;

/**
 * Flow documents.
 *  - One working draft per flow: saving overwrites it (draft_rev++, snapshot kept); only publish creates a version.
 *  - title / source_md / search_text / version = latest published (version 0 = never published).
 *  - Status lives in workflow scope "flow" (labels, transitions, terminal = no editing); viewer-visible statuses in lookup.
 *  - Draft editing: flow.manage, or an assigned editor who is still a project member.
 */
class FlowService
{
    private const SCOPE = 'flow';

    /** Format codes this code base can handle (behaviour lives here; label, order, enabled in lookup flow_format). */
    private const HANDLERS = ['mermaid', 'drawio'];

    public function __construct(
        private FlowRepository $repo,
        private FlowRenderer $renderer,
        private DrawioParser $drawio,
        private EventRepository $events,
        private ProjectRepository $projects,
        private WorkflowService $wf,
        private UnitOfWork $uow,
    ) {}

    // ---- permissions ----

    public function canView(User $u, int $pid): bool
    {
        return $this->wf->can($u, 'flow.view', $pid);
    }

    public function canManage(User $u, int $pid): bool
    {
        return $this->wf->can($u, 'flow.manage', $pid);
    }

    public function canDelete(User $u, int $pid): bool
    {
        return $this->wf->can($u, 'flow.delete', $pid);
    }

    public function isEditor(User $u, ProjectFlow $f): bool
    {
        return $this->repo->isEditor($f->id, $f->project_id, $u->id);
    }

    /** May see the draft (managers and assigned editors), whatever the status. */
    public function canSeeDraft(User $u, ProjectFlow $f): bool
    {
        return $this->canManage($u, $f->project_id) || $this->isEditor($u, $f);
    }

    public function isEditable(ProjectFlow $f): bool
    {
        return ! $this->wf->status(self::SCOPE, $f->status)->is_terminal;
    }

    public function canEditDraft(User $u, ProjectFlow $f): bool
    {
        return $this->isEditable($f) && $this->canSeeDraft($u, $f);
    }

    private function assertEditDraft(User $u, ProjectFlow $f): void
    {
        if (! $this->canSeeDraft($u, $f)) {
            throw new BusinessException('沒有權限編輯此流程圖。');
        }
        if (! $this->isEditable($f)) {
            throw new BusinessException('流程圖已「'.$this->statusLabel($f->status).'」，請先重新生效才能編輯。');
        }
    }

    // ---- lookups ----

    /** @return array<string,string> enabled format code => label */
    public function formats(): array
    {
        return array_intersect_key($this->wf->lookupMap('flow_format'), array_flip(self::HANDLERS));
    }

    public function formatLabel(string $format): string
    {
        return $this->wf->lookupLabel('flow_format', $format);
    }

    public function statusLabel(string $status): string
    {
        return $this->wf->label(self::SCOPE, $status);
    }

    /** @return string[] statuses members without edit rights may see (published flows only) */
    private function viewableStatuses(): array
    {
        return $this->wf->lookupCodes('flow_status_viewable');
    }

    /** @return array<string,string> status code => label the user may filter by (managers and assigned editors: all) */
    public function statusFilters(User $u, int $pid): array
    {
        $all = $this->wf->statusMap(self::SCOPE)->map(fn ($s) => $s->name)->all();
        if ($this->canManage($u, $pid) || $this->repo->editorFlowIds($pid, $u->id)) {
            return $all;
        }

        return array_intersect_key($all, array_flip($this->viewableStatuses()));
    }

    /** Filter used when the list is opened without ?status=: first viewer-visible status for members, all for managers. */
    public function defaultStatusFilter(User $u, int $pid): ?string
    {
        return $this->canManage($u, $pid) ? null : ($this->viewableStatuses()[0] ?? null);
    }

    private function initialStatus(): string
    {
        return $this->wf->initial('flow_initial', 'default');
    }

    private function assertFormat(string $format): void
    {
        if (! array_key_exists($format, $this->formats())) {
            throw new BusinessException('不支援的流程圖格式。');
        }
    }

    // ---- reading ----

    /** $status null = every status; members see viewer-visible published flows plus the flows they are an editor of. */
    public function list(User $u, int $pid, ?string $status, ?string $keyword = null): Collection
    {
        $keyword = $keyword !== null ? trim($keyword) : null;
        $rows = $this->repo->list($pid, $status !== null ? [$status] : null, [], $keyword);
        if ($this->canManage($u, $pid)) {
            return $rows;
        }
        $mine = $this->repo->editorFlowIds($pid, $u->id);
        $viewable = $this->viewableStatuses();

        return $rows->filter(fn (ProjectFlow $f) => in_array($f->id, $mine, true) || ($f->isPublished() && in_array($f->status, $viewable, true)))
            ->each(function (ProjectFlow $f) use ($mine) {
                if (! in_array($f->id, $mine, true)) {
                    $f->has_draft = 0; // draft existence is editor-only information
                }
            })->values();
    }

    /** Live flow of the current project the user may open. */
    public function find(User $u, int $pid, int $id): ProjectFlow
    {
        $f = $this->repo->find($id);
        if (! $f || $f->project_id !== $pid) {
            throw new BusinessException('找不到流程圖。');
        }
        $visible = $f->isPublished() && in_array($f->status, $this->viewableStatuses(), true);
        if (! $visible && ! $this->canSeeDraft($u, $f)) {
            throw new BusinessException('找不到流程圖。');
        }

        return $f;
    }

    /**
     * A version to show. $version: null = latest published (or the draft if never published), 'draft', or a number.
     *
     * @return array{kind:string, title:string, format:string, md:string, version:int, author:?string, at:mixed, note:?string, is_latest:bool}
     */
    public function version(User $u, int $pid, int $id, int|string|null $version): array
    {
        $f = $this->find($u, $pid, $id);
        $draft = fn () => ['kind' => 'draft', 'title' => $f->draft_title, 'format' => $f->format, 'md' => $f->draft_source,
            'version' => $f->version, 'author' => $f->draftEditor?->name, 'at' => $f->draft_updated_at, 'note' => null, 'is_latest' => false];

        if ($version === 'draft' || ($version === null && ! $f->isPublished())) {
            if (! $f->hasDraft() || ! $this->canSeeDraft($u, $f)) {
                throw new BusinessException('沒有草稿。');
            }

            return $draft();
        }
        $version = $version === null ? $f->version : (int) $version;
        $r = $this->repo->revision($f->id, $version) ?? throw new BusinessException('找不到該版本。');

        return ['kind' => 'published', 'title' => $r->title, 'format' => $r->format, 'md' => $r->source_md, 'version' => $r->version,
            'author' => $r->author?->name, 'at' => $r->created_at, 'note' => $r->note, 'is_latest' => $r->version === $f->version];
    }

    public function revisions(int $flowId): Collection
    {
        return $this->repo->revisions($flowId);
    }

    public function snapshots(User $u, ProjectFlow $f): Collection
    {
        return $this->canSeeDraft($u, $f) ? $this->repo->snapshots($f->id) : collect();
    }

    public function editors(int $flowId): Collection
    {
        return $this->repo->editors($flowId);
    }

    /** Project members that can be assigned as editors. */
    public function editorCandidates(int $pid): Collection
    {
        $p = $this->projects->find($pid);

        return $p ? $this->projects->members($p)->filter(fn (User $m) => $m->isActive())->values() : collect();
    }

    /** Status buttons (from workflow transitions); leaving the initial status happens only by publishing. */
    public function statusTargets(User $u, ProjectFlow $f): Collection
    {
        if ($f->status === $this->initialStatus()) {
            return collect();
        }

        return $this->wf->allowedTargets(self::SCOPE, $f->status, $u, $f->project_id);
    }

    public function render(string $md): string
    {
        return $this->renderer->render($md);
    }

    // ---- parsing ----

    /**
     * Structural checks only (mermaid syntax itself is validated in the browser).
     *
     * @return array{md:string, title:string, blocks:int}
     */
    public function parse(string $md, ?string $title, ?string $fileName): array
    {
        if (! mb_check_encoding($md, 'UTF-8')) {
            throw new BusinessException('檔案必須是 UTF-8 編碼。');
        }
        $md = str_replace(["\r\n", "\r"], "\n", preg_replace('/^\xEF\xBB\xBF/', '', $md));
        if (trim($md) === '') {
            throw new BusinessException('請上傳 MD 檔或貼上內容。');
        }
        // canonical form: form posts are trimmed by TrimStrings, files are not -> same content must compare equal
        $md = trim($md)."\n";
        $maxBytes = (int) $this->wf->setting('flow.max_bytes', '200000');
        if (strlen($md) > $maxBytes) {
            throw new BusinessException('檔案過大（上限 '.number_format($maxBytes).' bytes）。');
        }
        $blocks = count($this->renderer->blocks($md));
        if ($blocks === 0) {
            throw new BusinessException('內容中沒有 mermaid 區塊（```mermaid ... ```）。');
        }
        $maxBlocks = (int) $this->wf->setting('flow.max_blocks', '30');
        if ($blocks > $maxBlocks) {
            throw new BusinessException("流程圖區塊過多（上限 {$maxBlocks} 個）。");
        }

        $title = trim((string) $title);
        if ($title === '' && preg_match('/^#[ \t]+(.+?)[ \t#]*$/m', $md, $h)) {
            $title = trim($h[1]);
        }
        if ($title === '' && $fileName) {
            $title = trim(pathinfo($fileName, PATHINFO_FILENAME));
        }
        if ($title === '') {
            throw new BusinessException('請輸入標題。');
        }

        return ['md' => $md, 'title' => mb_substr($title, 0, 200), 'blocks' => $blocks];
    }

    /**
     * draw.io editable SVG (raw or data URI from the editor).
     *
     * @return array{md:string, title:string, search:string}
     */
    public function parseDrawio(string $svg, ?string $title): array
    {
        $title = trim((string) $title);
        if ($title === '') {
            throw new BusinessException('請輸入標題。');
        }
        $d = $this->drawio->parse($svg, (int) $this->wf->setting('flow.drawio_max_bytes', '5000000'));

        return ['md' => $d['svg'], 'title' => mb_substr($title, 0, 200), 'search' => implode("\n", $d['labels'])];
    }

    /** @return array{md:string, title:string, search:string} */
    private function prepare(string $format, string $source, ?string $title, ?string $fileName): array
    {
        $this->assertFormat($format);
        if ($format === 'drawio') {
            return $this->parseDrawio($source, $title);
        }
        $d = $this->parse($source, $title, $fileName);

        return ['md' => $d['md'], 'title' => $d['title'], 'search' => $d['md']];
    }

    // ---- writing ----

    /** New flow: starts as a draft (version 0) owned by the creator. */
    public function create(User $u, int $pid, string $format, string $source, ?string $title, ?string $fileName): ProjectFlow
    {
        $this->wf->authorize($u, 'flow.manage', $pid);
        $d = $this->prepare($format, $source, $title, $fileName);

        return $this->uow->run(function () use ($u, $pid, $format, $d) {
            $f = $this->repo->create([
                'project_id' => $pid, 'title' => $d['title'], 'format' => $format, 'status' => $this->initialStatus(),
                'source_md' => '', 'search_text' => null, 'version' => 0, 'sort' => 0, 'is_active' => true, 'updated_by' => $u->id,
                'draft_title' => $d['title'], 'draft_source' => $d['md'], 'draft_search_text' => $d['search'],
                'draft_rev' => 1, 'draft_updated_by' => $u->id, 'draft_updated_at' => now(),
            ]);
            $this->repo->syncEditors($f->id, [$u->id], $u->id);
            $this->snapshot($f, $u);
            $this->events->log($pid, 'flow', $f->id, 'flow_created', null, $f->status, $u->id, ['title' => $d['title'], 'format' => $format]);

            return $f;
        });
    }

    /**
     * Save the working draft (no new version). Empty title keeps the draft / current title.
     * $baseRev = draft_rev the editor started from; a newer saved draft rejects the save (optimistic lock).
     *
     * @return array{0: ProjectFlow, 1: bool} [flow, changed]
     */
    public function saveDraft(User $u, int $pid, int $id, string $source, ?string $title, ?string $fileName, ?int $baseRev = null): array
    {
        $f = $this->find($u, $pid, $id);
        $this->assertEditDraft($u, $f);
        $d = $this->prepare($f->format, $source, trim((string) $title) !== '' ? $title : ($f->draft_title ?? $f->title), $fileName);

        return $this->uow->run(function () use ($u, $f, $d, $baseRev) {
            $f = $this->repo->findForUpdate($f->id) ?? throw new BusinessException('找不到流程圖。');
            if ($baseRev !== null && $baseRev !== $f->draft_rev) {
                throw new BusinessException('草稿已被 '.($f->draftEditor?->name ?? '其他人').' 更新，你的修改沒有存入（編輯器草稿仍保留在這台電腦），請重新開啟編輯後再修改。');
            }
            [$curTitle, $curMd] = $f->hasDraft() ? [$f->draft_title, $f->draft_source] : [$f->title, $f->source_md];
            if ($d['md'] === $curMd && $d['title'] === $curTitle) {
                return [$f, false];
            }
            $this->repo->save($f, [
                'draft_title' => $d['title'], 'draft_source' => $d['md'], 'draft_search_text' => $d['search'],
                'draft_rev' => $f->draft_rev + 1, 'draft_updated_by' => $u->id, 'draft_updated_at' => now(),
            ]);
            $this->snapshot($f, $u);

            return [$f, true];
        });
    }

    private function snapshot(ProjectFlow $f, User $u): void
    {
        $this->repo->addSnapshot(['flow_id' => $f->id, 'draft_rev' => $f->draft_rev, 'title' => $f->draft_title, 'source' => $f->draft_source,
            'created_by' => $u->id, 'created_at' => now()]);
        $this->repo->pruneSnapshots($f->id, (int) $this->wf->setting('flow.draft_keep', '10'));
    }

    /** Draft -> new published version. From the initial status this is also the transition to the published status. */
    public function publish(User $u, int $pid, int $id, ?string $note): ProjectFlow
    {
        $this->wf->authorize($u, 'flow.manage', $pid);
        $f = $this->find($u, $pid, $id);
        $note = trim((string) $note);
        if ($note === '') {
            throw new BusinessException('請填寫變更說明。');
        }

        return $this->uow->run(function () use ($u, $pid, $f, $note) {
            $f = $this->repo->findForUpdate($f->id) ?? throw new BusinessException('找不到流程圖。');
            if (! $f->hasDraft()) {
                throw new BusinessException('沒有草稿可以發布。');
            }
            if (! $this->isEditable($f)) {
                throw new BusinessException('流程圖已「'.$this->statusLabel($f->status).'」，請先重新生效。');
            }
            $from = $f->status;
            $to = $from;
            if ($from === $this->initialStatus()) {
                $to = $this->wf->targetByPermission(self::SCOPE, $from, 'flow.manage', fn ($s) => ! $s->is_terminal)
                    ?? throw new BusinessException('缺少發布的狀態設定。');
                $this->wf->assertTransition(self::SCOPE, $from, $to, $u, $pid, ['note' => $note]);
            }
            $v = $f->version + 1;
            $this->repo->addRevision(['flow_id' => $f->id, 'version' => $v, 'title' => $f->draft_title, 'note' => mb_substr($note, 0, 500),
                'format' => $f->format, 'source_md' => $f->draft_source, 'created_by' => $u->id, 'created_at' => now()]);
            $this->repo->save($f, [
                'title' => $f->draft_title, 'source_md' => $f->draft_source, 'search_text' => $f->draft_search_text,
                'version' => $v, 'status' => $to, 'updated_by' => $u->id,
                'draft_title' => null, 'draft_source' => null, 'draft_search_text' => null, 'draft_updated_by' => null, 'draft_updated_at' => null,
            ]);
            $this->repo->clearSnapshots($f->id);
            $this->events->log($pid, 'flow', $f->id, 'flow_published', $from, $to, $u->id, ['version' => $v, 'title' => $f->title, 'note' => $note]);

            return $f;
        });
    }

    /** Drop the draft of a published flow (a never-published flow is deleted instead). */
    public function discardDraft(User $u, int $pid, int $id): ProjectFlow
    {
        $f = $this->find($u, $pid, $id);
        $this->assertEditDraft($u, $f);
        if (! $f->isPublished()) {
            throw new BusinessException('這份流程圖還沒發布過，不能捨棄草稿（要移除請用刪除）。');
        }
        if (! $f->hasDraft()) {
            throw new BusinessException('沒有草稿。');
        }

        return $this->uow->run(function () use ($u, $pid, $f) {
            $this->repo->save($f, ['draft_title' => null, 'draft_source' => null, 'draft_search_text' => null,
                'draft_rev' => $f->draft_rev + 1, 'draft_updated_by' => null, 'draft_updated_at' => null]);
            $this->repo->clearSnapshots($f->id);
            $this->events->log($pid, 'flow', $f->id, 'flow_draft_discarded', null, null, $u->id);

            return $f;
        });
    }

    /** Make an earlier save the current draft again. */
    public function restoreSnapshot(User $u, int $pid, int $id, int $snapshotId): ProjectFlow
    {
        $f = $this->find($u, $pid, $id);
        $s = $this->repo->snapshot($f->id, $snapshotId) ?? throw new BusinessException('找不到該次儲存。');
        [$f, $changed] = $this->saveDraft($u, $pid, $id, $s->source, $s->title, null);

        return $f;
    }

    public function changeStatus(User $u, int $pid, int $id, string $to): ProjectFlow
    {
        $f = $this->find($u, $pid, $id);
        if ($f->status === $this->initialStatus()) {
            throw new BusinessException('草稿請用「發布」生效。');
        }
        $from = $f->status;
        $this->wf->assertTransition(self::SCOPE, $from, $to, $u, $pid);
        $this->repo->save($f, ['status' => $to, 'updated_by' => $u->id]);
        $this->events->log($pid, 'flow', $f->id, 'flow_status', $from, $to, $u->id, ['title' => $f->title]);

        return $f;
    }

    /** @param int[] $userIds project members only; anything else is ignored */
    public function setEditors(User $u, int $pid, int $id, array $userIds): void
    {
        $this->wf->authorize($u, 'flow.manage', $pid);
        $f = $this->find($u, $pid, $id);
        $members = $this->editorCandidates($pid)->pluck('id')->all();
        $ids = array_values(array_intersect(array_map('intval', $userIds), $members));
        $this->repo->syncEditors($f->id, $ids, $u->id);
        $this->events->log($pid, 'flow', $f->id, 'flow_editors', null, null, $u->id, ['user_ids' => $ids]);
    }

    public function setSort(User $u, int $pid, int $id, int $sort): void
    {
        $this->wf->authorize($u, 'flow.manage', $pid);
        $this->repo->save($this->find($u, $pid, $id), ['sort' => $sort]);
    }

    // ---- delete / trash ----

    public function delete(User $u, int $pid, int $id): void
    {
        $this->wf->authorize($u, 'flow.delete', $pid);
        $f = $this->find($u, $pid, $id);
        $this->repo->save($f, ['deleted_at' => now(), 'deleted_by' => $u->id]);
        $this->events->log($pid, 'flow', $f->id, 'flow_deleted', null, null, $u->id, ['title' => $f->title]);
    }

    public function trashed(User $u, int $pid): Collection
    {
        $this->wf->authorize($u, 'project.trash', $pid);

        return $this->repo->trashed($pid);
    }

    private function findTrashed(int $pid, int $id): ProjectFlow
    {
        $f = $this->repo->findTrashed($id);
        if (! $f || $f->project_id !== $pid) {
            throw new BusinessException('回收筒裡找不到這份流程圖。');
        }

        return $f;
    }

    public function restore(User $u, int $pid, int $id): ProjectFlow
    {
        $this->wf->authorize($u, 'project.trash', $pid);
        $f = $this->findTrashed($pid, $id);
        $this->repo->save($f, ['deleted_at' => null, 'deleted_by' => null]);
        $this->events->log($pid, 'flow', $f->id, 'flow_restored', null, null, $u->id, ['title' => $f->title]);

        return $f;
    }

    /** Permanent delete; the caller must type the exact title. The event keeps title and version count. */
    public function purge(User $u, int $pid, int $id, string $confirmTitle): void
    {
        $this->wf->authorize($u, 'project.trash', $pid);
        $f = $this->findTrashed($pid, $id);
        $title = $f->title !== '' ? $f->title : (string) $f->draft_title;
        if (trim($confirmTitle) !== $title) {
            throw new BusinessException('輸入的標題不符，未刪除。');
        }
        $this->uow->run(function () use ($u, $pid, $f, $title) {
            $this->events->log($pid, 'flow', $f->id, 'flow_purged', null, null, $u->id, ['title' => $title, 'versions' => $this->repo->revisionCount($f->id)]);
            $this->repo->purge($f);
        });
    }

    /** Download filename: {title}_v{n}.md, or .drawio.svg (re-opens in draw.io desktop); draft: _草稿 */
    public function filename(string $title, int|string $version, string $format = 'mermaid'): string
    {
        $v = is_int($version) ? '_v'.$version : '_'.$version;

        return preg_replace('~[\\\\/:*?"<>|\s]+~u', '_', $title).$v.($format === 'drawio' ? '.drawio.svg' : '.md');
    }
}
