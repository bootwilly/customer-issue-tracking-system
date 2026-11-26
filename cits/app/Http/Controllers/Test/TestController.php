<?php

namespace App\Http\Controllers\Test;

use App\Exceptions\BusinessException;
use App\Http\Controllers\Controller;
use App\Services\Export\IssueExportService;
use App\Services\Export\XlsxExportService;
use App\Services\Test\TestService;
use App\Support\CurrentProject;
use Illuminate\Http\Request;

class TestController extends Controller
{
    public function __construct(private TestService $svc, private CurrentProject $project, private IssueExportService $export, private XlsxExportService $xlsx) {}

    private function guard(Request $r, bool $manage = false): int
    {
        $pid = (int) $this->project->id();
        abort_unless($this->svc->canView($r->user(), $pid) && (! $manage || $this->svc->canManage($r->user(), $pid)), 403);

        return $pid;
    }

    public function index(Request $r)
    {
        $pid = $this->guard($r);

        return view('test.index', $this->svc->index($r->user(), $pid, $r->query()) + ['canExport' => $this->export->canExport($r->user(), $pid)]);
    }

    public function export(Request $r)
    {
        $pid = $this->guard($r);
        abort_unless($this->export->canExport($r->user(), $pid), 403);
        try {
            $book = $this->export->testBook($r->user(), $pid, $r->query());
        } catch (BusinessException $e) {
            return redirect()->route('test.index', $r->query())->with('err', $e->getMessage());
        }

        return $this->xlsx->download($book, $r->user(), $pid, (string) $this->project->get()?->name);
    }

    public function create(Request $r)
    {
        $pid = $this->guard($r, true);

        return view('test.create', $this->svc->formOptions($pid));
    }

    public function store(Request $r)
    {
        $pid = $this->guard($r, true);
        $d = $r->validate([
            'title' => ['required', 'string', 'max:200'],
            'severity' => ['required', 'integer', 'between:1,5'],
            'assignee_dev_id' => ['required', 'integer'],
            'mes_page_id' => ['nullable', 'integer'],
            'content_html' => ['required', 'string'],
        ]);
        try {
            $issue = $this->svc->createIssue($r->user(), $pid, $d, 'issue:new:'.$pid);
        } catch (BusinessException $e) {
            return back()->with('err', $e->getMessage())->withInput();
        }

        return redirect('/issues/'.$issue->id)->with('ok', '已建立測試異常 '.$issue->issue_no);
    }
}
