<?php

namespace App\Http\Controllers\Dev;

use App\Exceptions\BusinessException;
use App\Http\Controllers\Controller;
use App\Services\Dev\DevService;
use App\Services\Export\IssueExportService;
use App\Services\Export\XlsxExportService;
use App\Support\CurrentProject;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class DevController extends Controller
{
    public function __construct(private DevService $svc, private CurrentProject $project, private IssueExportService $export, private XlsxExportService $xlsx) {}

    private function guard(Request $r, bool $manage = false): int
    {
        $pid = (int) $this->project->id();
        abort_unless($this->svc->canView($r->user(), $pid) && (! $manage || $this->svc->canManage($r->user(), $pid)), 403);

        return $pid;
    }

    public function index(Request $r)
    {
        $pid = $this->guard($r);

        return view('dev.index', $this->svc->index($r->user(), $pid, $r->query()) + ['canExport' => $this->export->canExport($r->user(), $pid)]);
    }

    public function export(Request $r)
    {
        $pid = $this->guard($r);
        abort_unless($this->export->canExport($r->user(), $pid), 403);
        try {
            $book = $this->export->devBook($r->user(), $pid, $r->query());
        } catch (BusinessException $e) {
            return redirect()->route('dev.index', $r->query())->with('err', $e->getMessage());
        }

        return $this->xlsx->download($book, $r->user(), $pid, (string) $this->project->get()?->name);
    }

    public function create(Request $r)
    {
        $pid = $this->guard($r, true);

        return view('dev.create', $this->svc->formOptions($pid));
    }

    public function store(Request $r)
    {
        $pid = $this->guard($r, true);
        $opts = $this->svc->formOptions($pid);
        $d = $r->validate([
            'title' => ['required', 'string', 'max:200'],
            'process_type' => ['nullable', Rule::in(array_keys($opts['processTypes']))],
            'mes_page_id' => ['nullable', 'integer'],
            'severity' => ['nullable', 'integer', 'between:1,5'],
            'content_html' => ['required', 'string'],
        ]);
        try {
            $issue = $this->svc->createIssue($r->user(), $pid, $d, 'issue:new:'.$pid);
        } catch (BusinessException $e) {
            return back()->with('err', $e->getMessage())->withInput();
        }

        return redirect('/issues/'.$issue->id)->with('ok', '已建立開發問題 '.$issue->issue_no);
    }

    public function storePackage(Request $r)
    {
        $pid = $this->guard($r, true);
        $d = $r->validate(['name' => ['required', 'string', 'max:150']]);
        try {
            $this->svc->createPackage($r->user(), $pid, $d['name']);
        } catch (BusinessException $e) {
            return back()->with('err', $e->getMessage())->withInput();
        }

        return back()->with('ok', '已建立更新包。');
    }

    public function addToPackage(Request $r, int $id)
    {
        $pid = $this->guard($r, true);
        $d = $r->validate(['issue_ids' => ['required', 'array', 'max:300'], 'issue_ids.*' => ['integer'], 'assignee_test_id' => ['nullable', 'integer']]);
        try {
            $n = $this->svc->addToPackage($r->user(), $pid, $id, $d['issue_ids'], isset($d['assignee_test_id']) ? (int) $d['assignee_test_id'] : null);
        } catch (BusinessException $e) {
            return back()->with('err', $e->getMessage());
        }

        return back()->with('ok', "已套入 {$n} 張問題單並送測。");
    }

    public function removeFromPackage(Request $r, int $id, int $issueId)
    {
        $pid = $this->guard($r, true);
        try {
            $withdrawn = $this->svc->removeFromPackage($r->user(), $pid, $id, $issueId);
        } catch (BusinessException $e) {
            return back()->with('err', $e->getMessage());
        }

        return back()->with('ok', $withdrawn ? '已移出更新包並撤回送測，已通知測試人員。' : '已移出更新包。');
    }
}
