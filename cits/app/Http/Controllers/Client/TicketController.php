<?php

namespace App\Http\Controllers\Client;

use App\Exceptions\BusinessException;
use App\Http\Controllers\Controller;
use App\Services\Client\ClientProgressService;
use App\Services\Collab\NeedinfoService;
use App\Services\Collab\WatchService;
use App\Services\Client\ClientQueryService;
use App\Services\Export\TicketExportService;
use App\Services\Export\XlsxExportService;
use App\Services\TicketService;
use App\Services\WorkflowService;
use App\Support\CurrentProject;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class TicketController extends Controller
{
    public function __construct(
        private TicketService $tickets,
        private WorkflowService $wf,
        private ClientQueryService $query,
        private CurrentProject $project,
        private TicketExportService $exports,
        private XlsxExportService $xlsx,
    ) {}

    private function pid(): int
    {
        return (int) $this->project->id();
    }

    private function requireCan(Request $r, string $perm): void
    {
        abort_unless($this->wf->can($r->user(), $perm, $this->pid()), 403);
    }

    public function index(Request $r): View
    {
        $this->requireCan($r, 'ticket.view');
        $u = $r->user();
        $tab = array_key_exists((string) $r->query('tab'), $this->query->tabs()) ? $r->query('tab') : 'open';
        $filters = [
            'type' => $r->query('type'),
            'q' => trim((string) $r->query('q')),
            'sort' => $r->query('sort', 'last'),
            'dir' => $r->query('dir', 'desc'),
            'mine' => $r->boolean('mine') ? $u->id : null,
            'ni' => in_array($r->query('ni'), ['waiting', 'answered'], true) ? $r->query('ni') : null,
        ];
        $filters['ids'] = app(NeedinfoService::class)->filterIds($this->pid(), $filters['ni']);
        $page = $this->query->paginate($this->pid(), $tab, $filters);

        return view('client.index', [
            'tab' => $tab,
            'tabs' => $this->query->tabs(),
            'counts' => $this->query->tabCounts($this->pid()),
            'page' => $page,
            'abilities' => $this->query->abilitiesMap($page->items(), $u),
            'needinfoStates' => app(NeedinfoService::class)->states(collect($page->items())->pluck('id')->all()),
            'progress' => app(ClientProgressService::class)->forTickets($page->items()),
            'types' => $this->query->typeOptions(),
            'filters' => $filters,
            'canCreate' => $u->actsAsClient() && $this->wf->can($u, 'ticket.create', $this->pid()),
            'canExport' => $this->wf->can($u, 'data.export', $this->pid()),
        ]);
    }

    public function export(Request $r): BinaryFileResponse|RedirectResponse
    {
        $this->requireCan($r, 'ticket.view');
        $this->requireCan($r, 'data.export');
        $u = $r->user();
        $filters = [
            'tab' => (string) $r->query('tab'),
            'type' => $r->query('type'),
            'q' => trim((string) $r->query('q')),
            'sort' => $r->query('sort', 'last'),
            'dir' => $r->query('dir', 'desc'),
            'mine' => $r->boolean('mine') ? $u->id : null,
            'ni' => in_array($r->query('ni'), ['waiting', 'answered'], true) ? $r->query('ni') : null,
        ];
        $filters['ids'] = app(NeedinfoService::class)->filterIds($this->pid(), $filters['ni']);
        try {
            $book = $this->exports->clientBook($u, $this->pid(), $filters);
        } catch (BusinessException $e) {
            return redirect()->route('tickets.index', $r->query())->with('err', $e->getMessage());
        }

        return $this->xlsx->download($book, $u, $this->pid(), (string) $this->project->get()?->name);
    }

    public function create(Request $r): View
    {
        $this->requireCan($r, 'ticket.create');
        abort_unless($r->user()->actsAsClient(), 403);

        return view('client.create', $this->formData());
    }

    public function store(Request $r): RedirectResponse
    {
        $this->requireCan($r, 'ticket.create');
        abort_unless($r->user()->actsAsClient(), 403);
        $data = $this->validated($r);
        try {
            $t = $this->tickets->create($r->user(), $this->pid(), $data, 'ticket:new:'.$this->pid());
        } catch (BusinessException $e) {
            return back()->with('err', $e->getMessage())->withInput();
        }

        return redirect()->route('tickets.show', $t->id)->with('ok', "已開立客戶單 {$t->ticket_no}");
    }

    public function show(Request $r, int $id): View|RedirectResponse
    {
        $this->requireCan($r, 'ticket.view');
        $u = $r->user();
        // Internal staff work tickets on the PM page (old notification links still point here).
        if (! $u->actsAsClient()) {
            return redirect()->route('pm.tickets.show', $id);
        }
        try {
            $t = $this->tickets->get($id, $this->pid());
            // Read receipts are shown to clients: only client readers count (admin browsing is silent).
            if ($u->isClient()) {
                $this->tickets->markRead($u, $id, $this->pid());
            }
        } catch (BusinessException $e) {
            return redirect()->route('tickets.index')->with('err', $e->getMessage());
        }
        $d = $this->query->detail($t);

        return view('client.show', [
            'ticket' => $d['ticket'],
            'readers' => $d['readers'],
            'abilities' => $this->tickets->abilities($t, $u),
            'timeline' => $this->tickets->timeline($t),
            'types' => $this->query->typeOptions(),
            'categories' => $this->query->categoryOptions(),
            'kinds' => $this->query->commentKinds(),
            'clientKinds' => $this->query->clientCommentKinds(),
            'pid' => $this->pid(),
            'needinfoOpen' => app(NeedinfoService::class)->open($t->id),
            'progress' => app(ClientProgressService::class)->forTicket($t),
            'progressSteps' => app(ClientProgressService::class)->steps(),
            'watching' => app(WatchService::class)->watchingTicket($u, $t->id),
        ]);
    }

    public function edit(Request $r, int $id): View|RedirectResponse
    {
        $this->requireCan($r, 'ticket.edit');
        // opening the page takes the edit lock: only for a real page load, not an embedded <img>/<script>/fetch
        $dest = $r->headers->get('Sec-Fetch-Dest');
        abort_if($dest !== null && ! in_array($dest, ['document', 'iframe'], true), 403);
        try {
            $t = $this->tickets->startEdit($r->user(), $id, $this->pid());
        } catch (BusinessException $e) {
            return redirect()->route('tickets.show', $id)->with('err', $e->getMessage());
        }

        return view('client.edit', [
            'ticket' => $t,
            'isRevision' => $this->tickets->abilities($t, $r->user())['edit_is_revision'],
        ] + $this->formData());
    }

    public function update(Request $r, int $id): RedirectResponse
    {
        $this->requireCan($r, 'ticket.edit');
        $data = $this->validated($r);
        try {
            $t = $this->tickets->saveEdit($r->user(), $id, $this->pid(), $data, (int) $r->input('row_version'), "ticket:edit:$id");
        } catch (BusinessException $e) {
            return back()->with('err', $e->getMessage())->withInput();
        }

        return redirect()->route('tickets.show', $t->id)->with('ok', '已儲存。');
    }

    public function heartbeat(Request $r, int $id): JsonResponse
    {
        try {
            $ok = $this->tickets->heartbeat($r->user(), $id, $this->pid());
        } catch (BusinessException) {
            $ok = false;
        }

        return response()->json(['ok' => $ok]);
    }

    public function release(Request $r, int $id): JsonResponse|RedirectResponse
    {
        $err = null;
        try {
            $this->tickets->releaseEdit($r->user(), $id, $this->pid());
        } catch (BusinessException $e) {
            $err = $e->getMessage();
        }
        if ($r->expectsJson() || $r->boolean('beacon')) {
            return response()->json(['ok' => $err === null]);
        }

        return redirect()->route('tickets.show', $id)->with($err ? ['err' => $err] : []);
    }

    public function comment(Request $r, int $id): RedirectResponse
    {
        $data = $r->validate(['kind' => ['required', 'string'], 'content_html' => ['required', 'string']], [
            'content_html.required' => '留言內容不可空白。',
        ]);

        return $this->act(fn () => $this->tickets->addComment($r->user(), $id, $this->pid(), $data['kind'], $data['content_html'], "ticket:comment:$id"), $id, '已送出留言。');
    }

    public function cancel(Request $r, int $id): RedirectResponse
    {
        $data = $r->validate(['reason' => ['nullable', 'string', 'max:500']]);

        return $this->act(fn () => $this->tickets->cancel($r->user(), $id, $this->pid(), $data['reason'] ?? null), $id, '已取消客戶單。');
    }

    public function close(Request $r, int $id): RedirectResponse
    {
        return $this->act(fn () => $this->tickets->close($r->user(), $id, $this->pid()), $id, '已結案。');
    }

    public function reopen(Request $r, int $id): RedirectResponse
    {
        $data = $r->validate(['content_html' => ['required', 'string']], ['content_html.required' => '請說明仍有異常的內容。']);

        return $this->act(fn () => $this->tickets->reopen($r->user(), $id, $this->pid(), $data['content_html'], "ticket:reopen:$id"), $id, '已提出異常，案件重新處理。');
    }

    private function act(callable $fn, int $id, string $ok): RedirectResponse
    {
        try {
            $fn();
        } catch (BusinessException $e) {
            return back()->with('err', $e->getMessage())->withInput();
        }

        return redirect()->route('tickets.show', $id)->with('ok', $ok);
    }

    private function formData(): array
    {
        return [
            'ticket' => null,
            'types' => $this->query->typeOptions(),
            'categories' => $this->query->categoryOptions(),
            'pages' => $this->query->pageOptions($this->pid()),
            'anomalyTypes' => $this->query->anomalyTypes(),
            'pid' => $this->pid(),
        ];
    }

    private function validated(Request $r): array
    {
        $d = $r->validate([
            'ticket_type' => ['required', Rule::in(array_keys($this->query->typeOptions()))],
            'anomaly_category' => ['nullable', Rule::in(array_keys($this->query->categoryOptions()))],
            'mes_page_id' => ['nullable', 'integer', Rule::in(array_keys($this->query->pageOptions($this->pid())))],
            'title' => ['required', 'string', 'max:200'],
            'content_html' => ['required', 'string'],
        ], [
            'ticket_type.required' => '請選擇類型。', 'title.required' => '請輸入標題。', 'content_html.required' => '內容不可空白。',
        ]);
        if (in_array($d['ticket_type'], $this->query->anomalyTypes(), true)) {
            if (empty($d['anomaly_category'])) {
                throw ValidationException::withMessages(['anomaly_category' => '請選擇異常類別。']);
            }
        } else {
            $d['anomaly_category'] = null;
        }
        $d['mes_page_id'] = $d['mes_page_id'] ?? null;

        return $d;
    }
}
