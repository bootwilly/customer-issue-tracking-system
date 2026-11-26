<?php

namespace App\Http\Controllers\Meeting;

use App\Exceptions\BusinessException;
use App\Http\Controllers\Controller;
use App\Models\Meeting;
use App\Models\MeetingItem;
use App\Services\AttachmentService;
use App\Services\Export\MeetingExportService;
use App\Services\Export\XlsxExportService;
use App\Services\Flow\FlowRenderer;
use App\Services\Meeting\MeetingDocuments;
use App\Services\Meeting\MeetingService;
use App\Support\CurrentProject;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class MeetingController extends Controller
{
    public function __construct(
        private MeetingService $svc,
        private MeetingDocuments $documents,
        private CurrentProject $project,
        private MeetingExportService $exporter,
        private XlsxExportService $xlsx,
    ) {}

    private function viewer(Request $r): int
    {
        $pid = (int) $this->project->id();
        abort_unless($this->svc->canView($r->user(), $pid), 403);

        return $pid;
    }

    private function back(int $id, string $msg, bool $ok = true)
    {
        return redirect()->route('meetings.show', $id)->with($ok ? 'ok' : 'err', $msg)->withInput($ok ? [] : request()->except(['_token', '_method']));
    }

    private function headerRules(): array
    {
        return [
            'type' => ['required', 'string', 'max:30'],
            'title' => ['required', 'string', 'max:200'],
            'start_at' => ['nullable', 'string', 'max:30'],
            'end_at' => ['nullable', 'string', 'max:30'],
            'location' => ['nullable', 'string', 'max:200'],
            'attendee_ids' => ['array'],
            'attendee_ids.*' => ['integer'],
            'external_attendees' => ['nullable', 'string', 'max:5000'],
        ];
    }

    public function index(Request $r)
    {
        $pid = $this->viewer($r);
        $u = $r->user();
        $tabs = $this->svc->tabs();
        $tab = array_key_exists((string) $r->query('tab'), $tabs) ? (string) $r->query('tab') : array_key_first($tabs);
        $q = trim((string) $r->query('q', ''));
        $f = $this->svc->filters($r->query());

        return view('meetings.index', $this->svc->list($u, $pid, $tab, $q !== '' ? $q : null, $f) + [
            'tabs' => $tabs,
            'tab' => $tab,
            'q' => $q,
            'f' => $f,
            'types' => $this->svc->types(),
            'statuses' => $this->svc->statuses(),
            'canExport' => $this->exporter->canExport($u, $pid),
            'canCreate' => $this->svc->canCreate($u, $pid),
            'svc' => $this->svc,
        ]);
    }

    /** Excel: every meeting matching the list's current tab / keyword / filters. */
    public function export(Request $r)
    {
        $pid = $this->viewer($r);
        $u = $r->user();
        abort_unless($this->exporter->canExport($u, $pid), 403);
        $tabs = $this->svc->tabs();
        $tab = array_key_exists((string) $r->query('tab'), $tabs) && $r->query('tab') !== 'mine' ? (string) $r->query('tab') : 'all';
        $q = trim((string) $r->query('q', ''));
        try {
            $book = $this->exporter->listBook($u, $pid, $tab, $q !== '' ? $q : null, $this->svc->filters($r->query()));
        } catch (BusinessException $e) {
            return redirect()->route('meetings.index', $r->query())->with('err', $e->getMessage());
        }

        return $this->xlsx->download($book, $u, $pid, (string) $this->project->get()?->name);
    }

    /** Excel: one meeting. */
    public function exportOne(Request $r, int $id)
    {
        $pid = $this->viewer($r);
        $u = $r->user();
        abort_unless($this->exporter->canExport($u, $pid), 403);
        try {
            $book = $this->exporter->oneBook($u, $pid, $id);
        } catch (BusinessException) {
            abort(404);
        }

        return $this->xlsx->download($book, $u, $pid, (string) $this->project->get()?->name);
    }

    public function create(Request $r)
    {
        $pid = $this->viewer($r);
        abort_unless($this->svc->canCreate($r->user(), $pid), 403);

        return view('meetings.form', [
            'meeting' => null,
            'issues' => $this->svc->prefillIssues($r->user(), $pid, (array) $r->query('issue_ids', [])),
            'types' => $this->svc->types(),
            'members' => $this->svc->members($pid),
            'attendeeIds' => [$r->user()->id],
            'externals' => '',
            'canShare' => $this->svc->canShare($r->user(), $pid),
        ]);
    }

    public function store(Request $r)
    {
        $pid = $this->viewer($r);
        $d = $r->validate($this->headerRules() + [
            'topics' => ['nullable', 'string', 'max:20000'],
            'customer_visible' => ['nullable', 'boolean'],
            'mode' => ['nullable', 'string', 'max:20'],
            'issue_ids' => ['array', 'max:100'],
            'issue_ids.*' => ['integer'],
        ]);
        try {
            $m = $this->svc->create($r->user(), $pid, $d);
        } catch (BusinessException $e) {
            return back()->with('err', $e->getMessage())->withInput();
        }

        return redirect()->route('meetings.show', $m->id)->with('ok', '已建立會議。');
    }

    public function show(Request $r, int $id)
    {
        $pid = $this->viewer($r);
        $u = $r->user();
        try {
            $m = $this->svc->find($u, $pid, $id);
        } catch (BusinessException) {
            abort(404);
        }
        $comments = $this->svc->comments($u, $m);
        $this->svc->markRead($u, $m);
        $canEdit = $this->svc->canEdit($u, $m);

        return view('meetings.show', [
            'm' => $m,
            'svc' => $this->svc,
            'isClient' => ! $u->seesInternal(),
            'documents' => $this->documents->visible($u, $m),
            'attendees' => $this->svc->attendees($m),
            'items' => $this->svc->items($u, $m),
            'comments' => $comments,
            'contentVisible' => $this->svc->contentVisible($u, $m),
            'canEdit' => $canEdit,
            'canShare' => $this->svc->canToggleShare($u, $m),
            'canPickIssues' => $this->svc->canPickIssues($u, $m),
            'issueStages' => $this->svc->issueStages(),
            'canComment' => $this->svc->canComment($u, $m),
            'canExport' => $this->exporter->canExport($u, $pid),
            'canCreate' => $this->svc->canCreate($u, $pid),
            'canDelete' => $this->svc->canDelete($u, $pid),
            'statusTargets' => $this->svc->statusTargets($u, $m),
            'members' => $canEdit ? $this->svc->members($pid) : collect(),
            'types' => $this->svc->types(),
            'itemStatuses' => $this->svc->itemStatuses(),
        ]);
    }

    private function documentMeeting(Request $r, int $id): Meeting
    {
        $pid = $this->viewer($r);
        try {
            return $this->svc->find($r->user(), $pid, $id);
        } catch (BusinessException) {
            abort(404);
        }
    }

    public function uploadDocument(Request $r, int $id, AttachmentService $attachments)
    {
        $m = $this->documentMeeting($r, $id);
        $internal = $r->boolean('internal');
        abort_unless($this->svc->canEdit($r->user(), $m) && (! $internal || $r->user()->seesInternal()), 403);
        $data = $r->validate(['file' => ['required', 'file'], 'internal' => ['required', 'boolean']]);
        try {
            $a = $attachments->storeMeetingMarkdown($r->user(), $data['file'], $this->svc->notesContext($id, $internal));
        } catch (BusinessException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['attachment_id' => $a->id, 'name' => $a->original_name,
            'size' => $a->size, 'url' => route('attachments.show', $a->id)]);
    }

    public function storeDocuments(Request $r, int $id)
    {
        $m = $this->documentMeeting($r, $id);
        $internal = $r->boolean('internal');
        abort_unless($this->svc->canEdit($r->user(), $m) && (! $internal || $r->user()->seesInternal()), 403);
        $data = $r->validate(['files' => ['required', 'array', 'min:1'], 'files.*' => ['required', 'file'], 'internal' => ['required', 'boolean']]);
        try {
            $this->documents->upload($r->user(), $m, $data['files'], $internal);
        } catch (BusinessException $e) {
            if ($r->expectsJson()) {
                return response()->json(['message' => $e->getMessage()], 422);
            }

            return back()->with('err', $e->getMessage());
        }
        if ($r->expectsJson()) {
            $documents = $this->documents->visible($r->user(), $m);

            return response()->json(['list' => view('meetings._document-list', compact('m', 'documents'))->render()]);
        }

        return redirect()->to(route('meetings.show', $id).'#documents')->with('ok', '文件已上傳。');
    }

    public function document(Request $r, int $id, int $document, AttachmentService $attachments, FlowRenderer $renderer)
    {
        $m = $this->documentMeeting($r, $id);
        $a = $this->documents->visible($r->user(), $m)->firstWhere('id', $document);
        abort_unless($a, 404);
        $path = $attachments->absolutePath($a);
        abort_unless($path, 404);
        $headers = ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff'];
        if ($r->boolean('download')) {
            return response()->file($path, $headers + ['Content-Type' => 'text/markdown'])
                ->setContentDisposition('attachment', $a->original_name, 'document-'.$document.'.md');
        }
        try {
            $html = $renderer->render($attachments->readMarkdown($path));
        } catch (BusinessException $e) {
            abort(422, $e->getMessage());
        }

        return response()->view('meetings.document', ['html' => $html, 'title' => $a->original_name], 200, $headers);
    }

    public function edit(Request $r, int $id)
    {
        $pid = $this->viewer($r);
        try {
            $m = $this->svc->find($r->user(), $pid, $id);
        } catch (BusinessException) {
            abort(404);
        }
        abort_unless($this->svc->canEdit($r->user(), $m), 403);
        $att = $this->svc->attendees($m);

        return view('meetings.form', [
            'meeting' => $m,
            'issues' => collect(),
            'types' => $this->svc->types() + [$m->type => $this->svc->typeLabel($m->type)],
            'members' => $this->svc->members($pid),
            'attendeeIds' => $att->pluck('user_id')->filter()->all(),
            'externals' => $att->pluck('external_name')->filter()->implode("\n"),
            'canShare' => false,
        ]);
    }

    public function update(Request $r, int $id)
    {
        $pid = $this->viewer($r);
        $d = $r->validate($this->headerRules());
        try {
            $this->svc->update($r->user(), $pid, $id, $d);
        } catch (BusinessException $e) {
            return back()->with('err', $e->getMessage())->withInput();
        }

        return $this->back($id, '已更新會議資料。');
    }

    public function status(Request $r, int $id)
    {
        $pid = $this->viewer($r);
        $d = $r->validate(['to' => ['required', 'string', 'max:30']]);
        try {
            $m = $this->svc->changeStatus($r->user(), $pid, $id, $d['to']);
        } catch (BusinessException $e) {
            return $this->back($id, $e->getMessage(), false);
        }

        return $this->back($id, '狀態已變更為「'.$this->svc->statusLabel($m->status).'」。');
    }

    public function share(Request $r, int $id)
    {
        $pid = $this->viewer($r);
        $d = $r->validate(['visible' => ['required', 'boolean']]);
        try {
            $m = $this->svc->share($r->user(), $pid, $id, (bool) $d['visible']);
        } catch (BusinessException $e) {
            return $this->back($id, $e->getMessage(), false);
        }

        return $this->back($id, $m->customer_visible ? '已開放給客戶。' : '已取消開放，客戶看不到這場會議。');
    }

    public function notes(Request $r, int $id)
    {
        $pid = $this->viewer($r);
        $d = $r->validate([
            'notes_html' => ['nullable', 'string'],
            'internal_html' => ['nullable', 'string'],
            'notes_rev' => ['nullable', 'integer', 'min:0'],
        ]);
        try {
            $this->svc->saveNotes($r->user(), $pid, $id, $d['notes_html'] ?? null, $d['internal_html'] ?? null,
                isset($d['notes_rev']) ? (int) $d['notes_rev'] : null);
        } catch (BusinessException $e) {
            return $this->back($id, $e->getMessage(), false);
        }

        return redirect()->to(route('meetings.show', $id).'#notes')->with('ok', '已儲存會議紀錄。');
    }

    public function next(Request $r, int $id)
    {
        $pid = $this->viewer($r);
        $d = $r->validate(['start_at' => ['required', 'string', 'max:30']], [], ['start_at' => '下次會議時間']);
        try {
            $m = $this->svc->scheduleNext($r->user(), $pid, $id, $d['start_at']);
        } catch (BusinessException $e) {
            return $this->back($id, $e->getMessage(), false);
        }

        return redirect()->route('meetings.show', $m->id)->with('ok', '已安排下次會議，未完成的討論事項已帶過來。');
    }

    public function ics(Request $r, int $id)
    {
        $pid = $this->viewer($r);
        try {
            $m = $this->svc->find($r->user(), $pid, $id);
            $body = $this->svc->ics($r->user(), $pid, $id, route('meetings.show', $id), $r->getHost());
        } catch (BusinessException) {
            abort(404);
        }

        return response()->streamDownload(fn () => print ($body), $this->svc->filename($m), [
            'Content-Type' => 'text/calendar; charset=utf-8',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function destroy(Request $r, int $id)
    {
        $pid = $this->viewer($r);
        abort_unless($this->svc->canDelete($r->user(), $pid), 403);
        try {
            $this->svc->delete($r->user(), $pid, $id);
        } catch (BusinessException $e) {
            return $this->back($id, $e->getMessage(), false);
        }

        return redirect()->route('meetings.index')->with('ok', '已移到回收筒（專案管理 → 回收筒可還原）。');
    }

    // ---- items (JSON from the item table, plain form posts as fallback) ----

    private function itemRules(): array
    {
        return [
            'topic' => ['sometimes', 'nullable', 'string', 'max:300'],
            'discussion' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'conclusion' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'owner_id' => ['sometimes', 'nullable', 'integer'],
            'due_date' => ['sometimes', 'nullable', 'string', 'max:10'],
            'status' => ['sometimes', 'string', 'max:16'],
            'issue_no' => ['sometimes', 'nullable', 'string', 'max:50'],
        ];
    }

    private function itemJson(MeetingItem $i): array
    {
        return [
            'id' => $i->id, 'topic' => $i->topic, 'discussion' => $i->discussion, 'conclusion' => $i->conclusion,
            'owner_id' => $i->owner_id, 'due_date' => $i->due_date?->format('Y-m-d'), 'status' => $i->status,
            'issue_no' => $i->issue?->issue_no, 'issue_url' => $i->issue ? route('issues.show', $i->issue->id) : null,
        ];
    }

    private function itemResult(Request $r, int $id, callable $fn, string $msg): JsonResponse|RedirectResponse
    {
        try {
            $item = $fn();
        } catch (BusinessException $e) {
            return $r->expectsJson() ? response()->json(['ok' => false, 'error' => $e->getMessage()], 422) : $this->back($id, $e->getMessage(), false);
        }

        return $r->expectsJson()
            ? response()->json(['ok' => true, 'item' => $item instanceof MeetingItem ? $this->itemJson($item) : null])
            : redirect()->to(route('meetings.show', $id).'#items')->with('ok', $msg);
    }

    public function storeItem(Request $r, int $id)
    {
        $pid = $this->viewer($r);
        $d = $r->validate($this->itemRules());

        return $this->itemResult($r, $id, fn () => $this->svc->addItem($r->user(), $pid, $id, $d), '已新增討論事項。');
    }

    public function updateItem(Request $r, int $id, int $item)
    {
        $pid = $this->viewer($r);
        $d = $r->validate($this->itemRules());

        return $this->itemResult($r, $id, fn () => $this->svc->updateItem($r->user(), $pid, $id, $item, $d), '已更新討論事項。');
    }

    public function destroyItem(Request $r, int $id, int $item)
    {
        $pid = $this->viewer($r);

        return $this->itemResult($r, $id, fn () => $this->svc->deleteItem($r->user(), $pid, $id, $item), '已刪除討論事項。');
    }

    public function moveItem(Request $r, int $id, int $item)
    {
        $pid = $this->viewer($r);
        $d = $r->validate(['dir' => ['required', 'in:up,down']]);

        return $this->itemResult($r, $id, fn () => $this->svc->moveItem($r->user(), $pid, $id, $item, $d['dir'] === 'up' ? -1 : 1), '已調整順序。');
    }

    /** Picker: issues of the project (JSON). ?q= keyword, ?open=1 non-terminal only, ?stage= workflow stage. */
    public function issueSearch(Request $r, int $id)
    {
        $pid = $this->viewer($r);
        $d = $r->validate(['q' => ['nullable', 'string', 'max:100'], 'open' => ['nullable', 'boolean'], 'stage' => ['nullable', 'string', 'max:10']]);
        try {
            $rows = $this->svc->searchIssues($r->user(), $pid, $id, $d['q'] ?? null, (bool) ($d['open'] ?? true), $d['stage'] ?? null);
        } catch (BusinessException $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 422);
        }

        return response()->json(['ok' => true, 'issues' => $rows]);
    }

    /** Picker: add the ticked issues as discussion items. */
    public function addIssues(Request $r, int $id)
    {
        $pid = $this->viewer($r);
        $d = $r->validate(['issue_ids' => ['required', 'array', 'max:100'], 'issue_ids.*' => ['integer']], [], ['issue_ids' => '問題單']);
        try {
            $n = $this->svc->addIssues($r->user(), $pid, $id, $d['issue_ids']);
        } catch (BusinessException $e) {
            return $r->expectsJson() ? response()->json(['ok' => false, 'error' => $e->getMessage()], 422) : $this->back($id, $e->getMessage(), false);
        }
        $msg = $n ? "已加入 {$n} 張問題單。" : '勾選的問題單都已在討論事項中。';
        if ($r->expectsJson()) {
            session()->flash('ok', $msg);

            return response()->json(['ok' => true, 'added' => $n]);
        }

        return redirect()->to(route('meetings.show', $id).'#items')->with('ok', $msg);
    }

    /** 問題管理 list: ticked issues -> an open meeting, or ("new") the new-meeting form with them. */
    public function bulkAddIssues(Request $r)
    {
        $pid = $this->viewer($r);
        $d = $r->validate([
            'meeting_id' => ['required', 'regex:/^(new|\d+)$/'],
            'issue_ids' => ['required', 'array', 'max:100'],
            'issue_ids.*' => ['integer'],
        ], ['issue_ids.required' => '請先勾選問題單。'], ['meeting_id' => '會議']);
        if ((string) $d['meeting_id'] === 'new') {
            abort_unless($this->svc->canCreate($r->user(), $pid), 403);

            return redirect()->route('meetings.create', ['issue_ids' => array_map('intval', $d['issue_ids'])]);
        }
        try {
            $n = $this->svc->addIssues($r->user(), $pid, (int) $d['meeting_id'], $d['issue_ids']);
        } catch (BusinessException $e) {
            return back()->with('err', $e->getMessage());
        }

        return redirect()->to(route('meetings.show', (int) $d['meeting_id']).'#items')
            ->with('ok', $n ? "已排入 {$n} 張問題單。" : '勾選的問題單都已在這場會議的討論事項中。');
    }

    /** Issue page: put the issue on an open meeting. */
    public function addIssue(Request $r)
    {
        $pid = $this->viewer($r);
        $d = $r->validate(['meeting_id' => ['required', 'integer'], 'issue_id' => ['required', 'integer']]);
        try {
            $m = $this->svc->addIssue($r->user(), $pid, (int) $d['meeting_id'], (int) $d['issue_id']);
        } catch (BusinessException $e) {
            return back()->with('err', $e->getMessage());
        }

        return back()->with('ok', '已排入會議「'.$m->title.'」。');
    }

    // ---- comments ----

    public function storeComment(Request $r, int $id)
    {
        $pid = $this->viewer($r);
        $d = $r->validate([
            'body' => ['required', 'string', 'max:2000'],
            'parent_id' => ['nullable', 'integer'],
            'is_internal' => ['nullable', 'boolean'],
        ], [], ['body' => '留言內容']);
        try {
            $c = $this->svc->addComment($r->user(), $pid, $id, $d['body'], isset($d['parent_id']) ? (int) $d['parent_id'] : null, (bool) ($d['is_internal'] ?? false));
        } catch (BusinessException $e) {
            return redirect()->to(route('meetings.show', $id).'#comments')->with('err', $e->getMessage())->withInput();
        }

        return redirect()->to(route('meetings.show', $id).'#c'.$c->id)->with('ok', '已送出留言。');
    }

    public function updateComment(Request $r, int $id, int $comment)
    {
        $pid = $this->viewer($r);
        $d = $r->validate(['body' => ['required', 'string', 'max:2000']], [], ['body' => '留言內容']);
        try {
            $this->svc->editComment($r->user(), $pid, $id, $comment, $d['body']);
        } catch (BusinessException $e) {
            return redirect()->to(route('meetings.show', $id).'#comments')->with('err', $e->getMessage());
        }

        return redirect()->to(route('meetings.show', $id).'#c'.$comment)->with('ok', '已修改留言。');
    }

    public function destroyComment(Request $r, int $id, int $comment)
    {
        $pid = $this->viewer($r);
        try {
            $this->svc->deleteComment($r->user(), $pid, $id, $comment);
        } catch (BusinessException $e) {
            return redirect()->to(route('meetings.show', $id).'#comments')->with('err', $e->getMessage());
        }

        return redirect()->to(route('meetings.show', $id).'#comments')->with('ok', '已刪除留言。');
    }
}
