<?php

namespace App\Http\Controllers\Pm;

use App\Exceptions\BusinessException;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Pm\Concerns\GuardsInternal;
use App\Services\IssueService;
use App\Services\Pm\PmQueryService;
use App\Services\TicketService;
use App\Services\WorkflowService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PmTicketController extends Controller
{
    use GuardsInternal;

    public function __construct(private TicketService $tickets, private IssueService $issues) {}

    public function show(Request $request, int $id, PmQueryService $q)
    {
        $u = $this->guard($request, 'issue.view');
        try {
            $data = $q->ticketPage($id, $this->pid(), $u);
        } catch (BusinessException) {
            abort(404);
        }

        return view('pm.ticket', $data);
    }

    public function accept(Request $request, int $id)
    {
        $u = $this->guard($request, 'issue.edit_pm');

        return $this->act(fn () => $this->tickets->accept($u, $id, $this->pid()), '已接收客戶單。');
    }

    public function ack(Request $request, int $id)
    {
        $u = $this->guard($request, 'issue.edit_pm');

        return $this->act(fn () => $this->tickets->ackRevisions($u, $id, $this->pid()), '已確認修訂內容。');
    }

    public function reply(Request $request, int $id)
    {
        $u = $this->guard($request, 'issue.edit_pm');
        $d = $request->validate(['content_html' => 'required|string', 'draft' => 'nullable|string|max:200']);

        return $this->act(fn () => $this->tickets->addComment($u, $id, $this->pid(), $this->tickets->internalReplyKind(), $d['content_html'], $d['draft'] ?? null), '已回覆客戶。');
    }

    public function settle(Request $request, int $id)
    {
        $u = $this->guard($request, 'issue.edit_pm');
        $d = $request->validate(['action' => 'required|in:pending,cancel', 'note' => 'required|string|max:500']);

        return $this->act(fn () => $this->tickets->settle($u, $id, $this->pid(), $d['action'], $d['note']), '已處置客戶單。');
    }

    public function createIssue(Request $request, int $id, PmQueryService $q)
    {
        $u = $this->guard($request, 'issue.edit_pm');
        $d = $request->validate([
            'title' => 'required|string|max:200',
            'process_type' => ['nullable', 'string', Rule::in(app(WorkflowService::class)->lookups('process_type')->pluck('code')->all())],
            'mes_page_id' => 'nullable|integer|exists:mes_pages,id',
            'severity' => 'required|integer|between:1,5',
            'content_html' => 'required|string',
            'extra_ticket_ids' => 'nullable|array', 'extra_ticket_ids.*' => 'integer',
            'draft' => 'nullable|string|max:200',
        ]);
        $ids = array_values(array_unique(array_merge([$id], array_map('intval', $d['extra_ticket_ids'] ?? []))));

        return $this->act(function () use ($u, $ids, $d) {
            $issue = $this->issues->createFromTickets($u, $this->pid(), $ids, $d, $d['draft'] ?? null);

            return redirect()->route('issues.show', $issue->id);
        }, '已建立內部單。');
    }

    public function link(Request $request, int $id)
    {
        $u = $this->guard($request, 'issue.edit_pm');
        $d = $request->validate(['issue_id' => 'required|integer']);

        return $this->act(fn () => $this->issues->link($u, (int) $d['issue_id'], $this->pid(), $id), '已連結到內部單。');
    }

    public function quote(Request $request, int $id)
    {
        $u = $this->guard($request, 'issue.edit_pm');
        $d = $request->validate(['issue_id' => 'required|integer', 'comment_id' => 'required|integer']);

        return $this->act(fn () => $this->issues->quoteComment($u, (int) $d['issue_id'], $this->pid(), (int) $d['comment_id']), '已引用到內部單補充說明。');
    }
}
