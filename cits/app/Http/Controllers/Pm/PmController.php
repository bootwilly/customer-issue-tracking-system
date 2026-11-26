<?php

namespace App\Http\Controllers\Pm;

use App\Exceptions\BusinessException;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Pm\Concerns\GuardsInternal;
use App\Services\Export\ExportBook;
use App\Services\Export\IssueExportService;
use App\Services\Export\TicketExportService;
use App\Services\Export\XlsxExportService;
use App\Services\IssueService;
use App\Services\Meeting\MeetingService;
use App\Services\Pm\PmQueryService;
use App\Services\WorkflowService;
use App\Support\CurrentProject;
use Illuminate\Http\Request;

class PmController extends Controller
{
    use GuardsInternal;

    public function index(Request $request, PmQueryService $q, MeetingService $meetings)
    {
        $u = $this->guard($request, 'issue.view');
        $f = $this->filters($request);

        return view('pm.index', $q->board($this->pid(), $u, $f) + [
            'f' => $f + ['oa' => $f['only_action'] ? '1' : '0'],
            'canExport' => app(WorkflowService::class)->can($u, 'data.export', $this->pid()),
            'meetingTargets' => $meetings->schedulableFor($u, $this->pid()),
            'canCreateMeeting' => $meetings->canCreate($u, $this->pid()),
        ]);
    }

    /** Excel export of both PM lists with the page's current filters: sheet 客戶單 + sheet 內部單 (each with a detail sheet). */
    public function export(Request $request, TicketExportService $tickets, IssueExportService $issues, XlsxExportService $xlsx, WorkflowService $wf)
    {
        $u = $this->guard($request, 'issue.view');
        $this->guard($request, 'data.export');
        $f = $this->filters($request);
        $project = app(CurrentProject::class)->get();
        try {
            $sheets = [$tickets->pmTicketsSheet($u, $this->pid(), $f), $issues->pmIssuesSheet($u, $this->pid(), $f)];
        } catch (BusinessException $e) {
            return redirect()->route('pm.index', $request->query())->with('err', $e->getMessage());
        }
        $label = fn (?string $v, string $all = '全部') => $v !== null && $v !== '' ? $v : $all;
        $info = [
            '客戶單：範圍' => ($f['tt'] ?? '') === 'all' ? '全部' : $wf->lookupLabel('client_tab', $wf->ticketScope($f['tt'] ?? null)),
            '客戶單：只看需處理' => $f['only_action'] && $wf->isDefaultTicketScope($f['tt'] ?? null) ? '是' : '否',
            '客戶單：關鍵字' => $label($f['tq'] ?? null, '（無）'),
            '客戶單：補充資料' => match ($f['ni'] ?? null) { 'waiting' => '等待客戶補充', 'answered' => '客戶已補充', default => '全部' },
            '內部單：階段' => $label($f['stage'] ?? null),
            '內部單：狀態' => ($f['status'] ?? '') !== '' ? $wf->label('issue', $f['status']) : '全部',
            '內部單：來源' => ($f['origin'] ?? '') !== '' ? $wf->lookupLabel('issue_origin', $f['origin']) : '全部',
            '內部單：關鍵字' => $label($f['q'] ?? null, '（無）'),
            '內部單：標籤（任一）' => ($f['tags'] ?? []) ? implode('、', array_map(fn ($c) => $wf->lookupLabel('issue_tag', $c), $f['tags'])) : '全部',
        ];

        return $xlsx->download(new ExportBook('pm', $xlsx->filename($project->code, '問題管理'), $sheets, $info), $u, $this->pid(), $project->name);
    }

    private function filters(Request $request): array
    {
        $f = $request->validate([
            'oa' => 'nullable|in:0,1', 'tt' => 'nullable|string|max:20', 'tq' => 'nullable|string|max:100', 'ni' => 'nullable|in:waiting,answered',
            'tags' => 'nullable|array|max:50', 'tags.*' => 'string|max:30',
            'stage' => 'nullable|string|max:10', 'status' => 'nullable|string|max:30', 'origin' => 'nullable|string|max:10',
            'q' => 'nullable|string|max:100', 'sort' => 'nullable|in:created,last,severity,due',
        ]);
        $f['only_action'] = (string) ($f['oa'] ?? '1') === '1';

        return $f;
    }

    public function reviewDue(Request $request, int $id, IssueService $issues)
    {
        $u = $this->guard($request, 'due.approve');
        $approve = (string) $request->validate(['approve' => 'required|in:0,1'])['approve'] === '1';

        return $this->act(fn () => $issues->reviewDueChange($u, $id, $this->pid(), $approve), $approve ? '已核准延期申請。' : '已駁回延期申請。');
    }
}
