<?php

namespace App\Http\Controllers\Issue;

use App\Exceptions\BusinessException;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Pm\Concerns\GuardsInternal;
use App\Services\IssueCloseService;
use App\Services\IssueService;
use App\Services\IssueTrashService;
use App\Services\Pm\PmQueryService;
use Illuminate\Http\Request;

class IssueController extends Controller
{
    use GuardsInternal;

    public function __construct(private IssueCloseService $close, private IssueService $issues, private PmQueryService $q, private IssueTrashService $trash) {}

    public function show(Request $request, int $id)
    {
        $u = $this->guard($request, 'issue.view');
        try {
            $data = $this->q->issuePage($id, $this->pid(), $u);
        } catch (BusinessException) {
            $gone = $this->trash->tombstone($u, $this->pid(), $id) ?? abort(404);

            return response()->view('issues.deleted', ['gone' => $gone, 'canTrash' => $this->trash->canTrash($u, $this->pid())], 404);
        }

        return view('issues.show', $data);
    }

    /** Move a new issue to the project recycle bin. */
    public function destroy(Request $request, int $id)
    {
        $u = $this->guard($request, 'issue.view');
        try {
            $i = $this->trash->delete($u, $this->pid(), $id);
        } catch (BusinessException $e) {
            return back()->with('err', $e->getMessage());
        }

        return redirect()->route('pm.index')->with('ok', "已將 {$i->issue_no} 移到回收筒。");
    }

    /** 問題管理 list: move the checked issues to the recycle bin (issues that may not be deleted are skipped). */
    public function bulkDestroy(Request $request)
    {
        $u = $this->guard($request, 'issue.view');
        $d = $request->validate(['issue_ids' => 'required|array|max:'.IssueTrashService::BULK_MAX, 'issue_ids.*' => 'integer'], [], ['issue_ids' => '內部單']);
        try {
            $r = $this->trash->bulkDelete($u, $this->pid(), $d['issue_ids']);
        } catch (BusinessException $e) {
            return back()->with('err', $e->getMessage());
        }
        $res = back();
        if ($r['deleted']) {
            $res->with('ok', '已將 '.count($r['deleted']).' 張移到回收筒：'.implode('、', $r['deleted']).'。');
        }
        if ($r['skipped']) {
            $res->with('err', '未刪除：'.implode('；', array_map(fn ($s) => "{$s['no']} {$s['reason']}", $r['skipped'])));
        }

        return $res;
    }

    public function transition(Request $request, int $id)
    {
        $u = $this->guard($request, 'issue.view');
        $in = $request->validate([
            'to' => 'required|string|max:30',
            'assignee_dev_id' => 'nullable|integer', 'assignee_test_id' => 'nullable|integer',
            'planned_due' => 'nullable|date', 'test_scheduled_at' => 'nullable|date', 'release_planned_at' => 'nullable|date',
            'package_id' => 'nullable|integer', 'new_package_name' => 'nullable|string|max:150',
            'close_reason' => 'nullable|string|max:1000',
            'cancel_reason' => 'nullable|string|max:500', 'client_note' => 'nullable|string|max:500',
        ]);

        return $this->act(function () use ($u, $id, $in) {
            $data = $this->q->transitionInput($id, $this->pid(), $u, $in['to'], $in);
            if ($this->close->resolved($in['to'])) {
                $this->close->request($u, $this->pid(), $id, $in['to'], $data['close_reason'] ?? '', $data['client_note'] ?? null);

                return;
            }
            $this->issues->transition($u, $id, $this->pid(), $in['to'], $data, $in['new_package_name'] ?? null);
        }, '狀態已更新。');
    }

    public function finishCloseTask(Request $request, int $id, int $task)
    {
        $u = $this->guard($request, 'issue.view');
        $d = $request->validate(['note' => 'required|string|max:1000']);

        return $this->act(fn () => $this->close->finishTask($u, $this->pid(), $task, $d['note'], $id), '已確認收尾。');
    }

    public function withdrawClose(Request $request, int $id)
    {
        $u = $this->guard($request, 'issue.close');

        return $this->act(fn () => $this->close->withdraw($u, $this->pid(), $id), '已撤回結案。');
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
