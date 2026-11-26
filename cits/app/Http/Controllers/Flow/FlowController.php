<?php

namespace App\Http\Controllers\Flow;

use App\Exceptions\BusinessException;
use App\Http\Controllers\Controller;
use App\Services\Flow\FlowService;
use App\Support\CurrentProject;
use Illuminate\Http\Request;

class FlowController extends Controller
{
    private const EXT = ['md', 'markdown', 'txt'];

    public function __construct(private FlowService $svc, private CurrentProject $project) {}

    private function viewer(Request $r): int
    {
        $pid = (int) $this->project->id();
        abort_unless($this->svc->canView($r->user(), $pid), 403);

        return $pid;
    }

    private function manager(Request $r): int
    {
        $pid = (int) $this->project->id();
        abort_unless($this->svc->canManage($r->user(), $pid), 403);

        return $pid;
    }

    /** ?v= : a version number or "draft"; empty = default. */
    private function versionParam(Request $r): int|string|null
    {
        $v = (string) $r->query('v', '');

        return $v === 'draft' ? 'draft' : ((int) $v ?: null);
    }

    private function back(Request $r, int $id, string $msg, bool $ok = true)
    {
        $to = route('flows.show', $id);
        if ($r->expectsJson()) {
            if ($ok) {
                session()->flash('ok', $msg);
            }

            return response()->json(['ok' => $ok, $ok ? 'redirect' : 'error' => $ok ? $to : $msg], $ok ? 200 : 422);
        }

        return redirect($to)->with($ok ? 'ok' : 'err', $msg);
    }

    public function index(Request $r)
    {
        $pid = $this->viewer($r);
        $u = $r->user();
        $q = trim((string) $r->query('q', ''));
        $filters = $this->svc->statusFilters($u, $pid);
        $status = $r->has('status') ? (string) $r->query('status') : $this->svc->defaultStatusFilter($u, $pid);
        $status = array_key_exists((string) $status, $filters) ? $status : null;

        return view('flows.index', [
            'flows' => $this->svc->list($u, $pid, $status, $q !== '' ? mb_substr($q, 0, 100) : null),
            'q' => $q,
            'status' => $status,
            'statusFilters' => $filters,
            'formats' => $this->svc->formats(),
            'canManage' => $this->svc->canManage($u, $pid),
        ]);
    }

    public function show(Request $r, int $id)
    {
        $pid = $this->viewer($r);
        $u = $r->user();
        try {
            $flow = $this->svc->find($u, $pid, $id);
            $doc = $this->svc->version($u, $pid, $id, $this->versionParam($r));
        } catch (BusinessException) {
            abort(404);
        }
        $canManage = $this->svc->canManage($u, $pid);

        return view('flows.show', [
            'flow' => $flow,
            'doc' => $doc,
            'html' => $doc['format'] === 'mermaid' ? $this->svc->render($doc['md']) : null,
            'formatLabel' => $this->svc->formatLabel($doc['format']),
            'statusLabel' => $this->svc->statusLabel($flow->status),
            'isTerminal' => ! $this->svc->isEditable($flow),
            'revisions' => $this->svc->revisions($flow->id),
            'canManage' => $canManage,
            'canSeeDraft' => $this->svc->canSeeDraft($u, $flow),
            'canEditDraft' => $this->svc->canEditDraft($u, $flow),
            'canDelete' => $this->svc->canDelete($u, $pid),
            'statusTargets' => $this->svc->statusTargets($u, $flow),
            'snapshots' => $this->svc->snapshots($u, $flow),
            'editors' => $canManage ? $this->svc->editors($flow->id) : collect(),
            'editorCandidates' => $canManage ? $this->svc->editorCandidates($pid) : collect(),
        ]);
    }

    /** MD import: validate + render without writing. flow_id set = new draft content for that flow. */
    public function preview(Request $r)
    {
        $pid = $this->viewer($r);
        $d = $r->validate([
            'title' => ['nullable', 'string', 'max:200'],
            'md_file' => ['nullable', 'file', 'max:2048'],
            'md_text' => ['nullable', 'string', 'max:2000000'],
            'flow_id' => ['nullable', 'integer'],
        ]);
        $file = $r->file('md_file');
        if ($file && ! in_array(strtolower($file->getClientOriginalExtension()), self::EXT, true)) {
            return back()->with('err', '副檔名必須是 .md、.markdown 或 .txt。')->withInput();
        }
        $md = $file ? (string) $file->get() : (string) ($d['md_text'] ?? '');
        $fileName = $file?->getClientOriginalName();

        try {
            $flow = ! empty($d['flow_id']) ? $this->svc->find($r->user(), $pid, (int) $d['flow_id']) : null;
            if ($flow ? ! $this->svc->canEditDraft($r->user(), $flow) : ! $this->svc->canManage($r->user(), $pid)) {
                abort(403);
            }
            if ($flow && $flow->format !== 'mermaid') {
                throw new BusinessException('此流程圖是線上繪製格式，請用「線上編輯」更新。');
            }
            $current = $flow ? ($flow->draft_title ?? $flow->title) : null;
            $parsed = $this->svc->parse($md, ($d['title'] ?? '') !== '' ? $d['title'] : $current, $fileName);
        } catch (BusinessException $e) {
            return back()->with('err', $e->getMessage())->withInput($r->except('md_file'));
        }
        $base = $flow ? ($flow->hasDraft() ? [$flow->draft_title, $flow->draft_source] : [$flow->title, $flow->source_md]) : null;

        return view('flows.preview', [
            'flow' => $flow,
            'parsed' => $parsed,
            'fileName' => $fileName,
            'html' => $this->svc->render($parsed['md']),
            'unchanged' => $base && $base[1] === $parsed['md'] && $base[0] === $parsed['title'],
        ]);
    }

    private function saveRules(): array
    {
        return [
            'title' => ['required', 'string', 'max:200'],
            'format' => ['nullable', 'string', 'max:16'],
            // drawio SVG may carry pasted images; the real byte limit is the flow.*max_bytes setting checked in the service
            'source_md' => ['required', 'string', 'max:12000000'],
            'file_name' => ['nullable', 'string', 'max:255'],
            'base_rev' => ['nullable', 'integer', 'min:0'],
        ];
    }

    /** New flow (starts as a draft). */
    public function store(Request $r)
    {
        $pid = $this->manager($r);
        $d = $r->validate($this->saveRules());
        try {
            $f = $this->svc->create($r->user(), $pid, $d['format'] ?? 'mermaid', $d['source_md'], $d['title'], $d['file_name'] ?? null);
        } catch (BusinessException $e) {
            return $r->expectsJson() ? response()->json(['ok' => false, 'error' => $e->getMessage()], 422) : redirect()->route('flows.index')->with('err', $e->getMessage());
        }

        return $this->back($r, $f->id, '已建立草稿，確認後請按「發布」才會生效。');
    }

    /** Save the working draft (never a new version). */
    public function update(Request $r, int $id)
    {
        $pid = $this->viewer($r);
        $d = $r->validate($this->saveRules());
        try {
            [, $changed] = $this->svc->saveDraft($r->user(), $pid, $id, $d['source_md'], $d['title'], $d['file_name'] ?? null,
                isset($d['base_rev']) ? (int) $d['base_rev'] : null);
        } catch (BusinessException $e) {
            return $this->back($r, $id, $e->getMessage(), false);
        }

        return $this->back($r, $id, $changed ? '草稿已儲存（尚未發布）。' : '內容沒有變更。');
    }

    /** Online editor for a new drawio flow. */
    public function create(Request $r)
    {
        $this->manager($r);
        abort_unless(array_key_exists('drawio', $this->svc->formats()), 404);

        return view('flows.editor', ['flow' => null, 'doc' => null, 'sourceUrl' => null]);
    }

    /** Online editor: opens the draft, else the latest version; ?v= starts the draft from an older version. */
    public function edit(Request $r, int $id)
    {
        $pid = $this->viewer($r);
        try {
            $flow = $this->svc->find($r->user(), $pid, $id);
            abort_unless($this->svc->canSeeDraft($r->user(), $flow), 403);
            if (! $this->svc->isEditable($flow)) {
                throw new BusinessException('流程圖已「'.$this->svc->statusLabel($flow->status).'」，請先重新生效才能編輯。');
            }
            if ($flow->format !== 'drawio') {
                throw new BusinessException('此流程圖是 MD 格式，請用「上傳新內容」更新草稿。');
            }
            $v = $this->versionParam($r) ?? ($flow->hasDraft() ? 'draft' : null);
            $doc = $this->svc->version($r->user(), $pid, $id, $v);
        } catch (BusinessException $e) {
            return redirect()->route('flows.show', $id)->with('err', $e->getMessage());
        }

        return view('flows.editor', [
            'flow' => $flow,
            'doc' => $doc,
            'sourceUrl' => route('flows.image', ['id' => $id, 'v' => $doc['kind'] === 'draft' ? 'draft' : $doc['version']]),
        ]);
    }

    /** Stored drawio SVG, for <img> and for re-opening in the editor. Locked down: never runs as a document. */
    public function image(Request $r, int $id)
    {
        $pid = $this->viewer($r);
        try {
            $doc = $this->svc->version($r->user(), $pid, $id, $this->versionParam($r));
        } catch (BusinessException) {
            abort(404);
        }
        abort_unless($doc['format'] === 'drawio', 404);

        return response($doc['md'], 200, [
            'Content-Type' => 'image/svg+xml; charset=utf-8',
            'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'; img-src data:; font-src data:; sandbox",
            'X-Content-Type-Options' => 'nosniff',
            // drafts change under the same URL
            'Cache-Control' => $doc['kind'] === 'draft' ? 'private, no-store' : 'private, max-age=300',
        ]);
    }

    public function download(Request $r, int $id)
    {
        $pid = $this->viewer($r);
        try {
            $doc = $this->svc->version($r->user(), $pid, $id, $this->versionParam($r));
        } catch (BusinessException) {
            abort(404);
        }
        $svg = $doc['format'] === 'drawio';
        $name = $this->svc->filename($doc['title'], $doc['kind'] === 'draft' ? '草稿' : $doc['version'], $doc['format']);

        return response()->streamDownload(fn () => print ($doc['md']), $name, [
            'Content-Type' => $svg ? 'image/svg+xml; charset=utf-8' : 'text/markdown; charset=utf-8',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function publish(Request $r, int $id)
    {
        $pid = $this->manager($r);
        $d = $r->validate(['note' => ['required', 'string', 'max:500']], [], ['note' => '變更說明']);
        try {
            $f = $this->svc->publish($r->user(), $pid, $id, $d['note']);
        } catch (BusinessException $e) {
            return $this->back($r, $id, $e->getMessage(), false);
        }

        return $this->back($r, $id, "已發布第 {$f->version} 版。");
    }

    public function discard(Request $r, int $id)
    {
        $pid = $this->viewer($r);
        try {
            $this->svc->discardDraft($r->user(), $pid, $id);
        } catch (BusinessException $e) {
            return $this->back($r, $id, $e->getMessage(), false);
        }

        return $this->back($r, $id, '已捨棄草稿。');
    }

    public function restoreSnapshot(Request $r, int $id, int $snapshot)
    {
        $pid = $this->viewer($r);
        try {
            $this->svc->restoreSnapshot($r->user(), $pid, $id, $snapshot);
        } catch (BusinessException $e) {
            return $this->back($r, $id, $e->getMessage(), false);
        }

        return redirect()->route('flows.show', ['id' => $id, 'v' => 'draft'])->with('ok', '已還原為草稿。');
    }

    public function status(Request $r, int $id)
    {
        $pid = $this->manager($r);
        $d = $r->validate(['to' => ['required', 'string', 'max:30']]);
        try {
            $f = $this->svc->changeStatus($r->user(), $pid, $id, $d['to']);
        } catch (BusinessException $e) {
            return $this->back($r, $id, $e->getMessage(), false);
        }

        return $this->back($r, $id, '狀態已變更為「'.$this->svc->statusLabel($f->status).'」。');
    }

    public function editors(Request $r, int $id)
    {
        $pid = $this->manager($r);
        $d = $r->validate(['user_ids' => ['array'], 'user_ids.*' => ['integer']]);
        try {
            $this->svc->setEditors($r->user(), $pid, $id, $d['user_ids'] ?? []);
        } catch (BusinessException $e) {
            return $this->back($r, $id, $e->getMessage(), false);
        }

        return $this->back($r, $id, '已更新指定編輯者。');
    }

    public function sort(Request $r, int $id)
    {
        $pid = $this->manager($r);
        $d = $r->validate(['sort' => ['required', 'integer', 'min:0', 'max:60000']]);
        try {
            $this->svc->setSort($r->user(), $pid, $id, (int) $d['sort']);
        } catch (BusinessException $e) {
            return back()->with('err', $e->getMessage());
        }

        return back()->with('ok', '已更新排序。');
    }

    public function destroy(Request $r, int $id)
    {
        $pid = $this->viewer($r);
        abort_unless($this->svc->canDelete($r->user(), $pid), 403);
        try {
            $this->svc->delete($r->user(), $pid, $id);
        } catch (BusinessException $e) {
            return $this->back($r, $id, $e->getMessage(), false);
        }

        return redirect()->route('flows.index')->with('ok', '已移到回收筒（專案管理 → 回收筒可還原）。');
    }
}
