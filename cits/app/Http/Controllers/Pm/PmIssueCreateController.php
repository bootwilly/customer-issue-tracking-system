<?php

namespace App\Http\Controllers\Pm;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Pm\Concerns\GuardsInternal;
use App\Services\IssueService;
use App\Services\Pm\PmQueryService;
use App\Services\WorkflowService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PmIssueCreateController extends Controller
{
    use GuardsInternal;

    public function create(Request $request, PmQueryService $q)
    {
        $this->guard($request, 'issue.create');
        $this->guard($request, 'issue.edit_pm');

        return view('pm.issue-create', $q->createFormData($this->pid()) + ['pid' => $this->pid()]);
    }

    public function store(Request $request, IssueService $issues, WorkflowService $wf)
    {
        $u = $this->guard($request, 'issue.edit_pm');
        $d = $request->validate([
            'title' => 'required|string|max:200',
            'process_type' => ['nullable', 'string', Rule::in($wf->lookups('process_type')->pluck('code')->all())],
            'mes_page_id' => 'nullable|integer|exists:mes_pages,id',
            'severity' => 'required|integer|between:1,5',
            'content_html' => 'required|string',
            'draft' => 'nullable|string|max:200',
        ]);

        return $this->act(function () use ($issues, $u, $d) {
            $issue = $issues->createInternal($u, $this->pid(), 'pm', $d, $d['draft'] ?? null);

            return redirect()->route('issues.show', $issue->id);
        }, '已建立內部單。');
    }
}
