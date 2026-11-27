<?php

namespace App\Http\Controllers\Issue;

use App\Exceptions\BusinessException;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Pm\Concerns\GuardsInternal;
use App\Services\IssueService;
use App\Services\Pm\PmQueryService;
use Illuminate\Http\Request;

class IssueController extends Controller
{
    use GuardsInternal;

    public function __construct(private IssueService $issues, private PmQueryService $q) {}

    public function show(Request $request, int $id)
    {
        $u = $this->guard($request, 'issue.view');
        try {
            $data = $this->q->issuePage($id, $this->pid(), $u);
        } catch (BusinessException) {
            abort(404);
        }

        return view('issues.show', $data);
    }

    public function transition(Request $request, int $id)
    {
        $u = $this->guard($request, 'issue.view');
        $in = $request->validate([
            'to' => 'required|string|max:30',
            'assignee_dev_id' => 'nullable|integer', 'assignee_test_id' => 'nullable|integer',
            'planned_due' => 'nullable|date', 'test_scheduled_at' => 'nullable|date', 'release_planned_at' => 'nullable|date',
            'package_id' => 'nullable|integer', 'new_package_name' => 'nullable|string|max:150',
            'cancel_reason' => 'nullable|string|max:500', 'client_note' => 'nullable|string|max:500',
        ]);

        return $this->act(function () use ($u, $id, $in) {
            $data = $this->q->transitionInput($id, $this->pid(), $u, $in['to'], $in);
            $this->issues->transition($u, $id, $this->pid(), $in['to'], $data, $in['new_package_name'] ?? null);
        }, '狀態已更新。');
    }

    public function fields(Request $request, int $id)
    {
        $u = $this->guard($request, 'issue.view');
        $in = $request->validate([
            'severity' => 'nullable|integer|between:1,5', 'difficulty' => 'nullable|integer|between:1,5',
            'title' => 'nullable|string|max:200', 'content_html' => 'nullable|string',
            'process_type' => 'nullable|string|max:30', 'mes_page_id' => 'nullable|integer|exists:mes_pages,id',
            'planned_start' => 'nullable|date', 'dev_note' => 'nullable|string|max:5000',
        ]);
        $draft = $request->validate(['draft' => 'nullable|string|max:100'])['draft'] ?? null;

        return $this->act(function () use ($u, $id, $in, $draft) {
            $this->issues->updateFields($u, $id, $this->pid(), $this->q->editableInput($id, $this->pid(), $u, $in), $draft);
        }, '已更新欄位。');
    }

    public function setDue(Request $request, int $id)
    {
        $u = $this->guard($request, 'issue.view');
        $d = $request->validate(['date' => 'required|date']);

        return $this->act(fn () => $this->issues->setInitialDue($u, $id, $this->pid(), $d['date']), '已設定預計完工日。');
    }

    public function requestDue(Request $request, int $id)
    {
        $u = $this->guard($request, 'issue.view');
        $d = $request->validate(['new_due' => 'required|date', 'reason' => 'required|string|max:500']);

        return $this->act(fn () => $this->issues->requestDueChange($u, $id, $this->pid(), $d['new_due'], $d['reason']), '已送出延期申請。');
    }

    public function addendum(Request $request, int $id)
    {
        $u = $this->guard($request, 'issue.view');
        $d = $request->validate(['content_html' => 'required|string', 'draft' => 'nullable|string|max:200']);

        return $this->act(fn () => $this->issues->addAddendum($u, $id, $this->pid(), $d['content_html'], $d['draft'] ?? null), '已新增補充說明。');
    }

    public function comment(Request $request, int $id)
    {
        $u = $this->guard($request, 'issue.view');
        $d = $request->validate(['content_html' => 'required|string', 'draft' => 'nullable|string|max:200']);

        return $this->act(fn () => $this->issues->addComment($u, $id, $this->pid(), $d['content_html'], $d['draft'] ?? null), '已新增留言。');
    }
}
