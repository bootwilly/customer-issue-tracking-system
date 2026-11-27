<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Services\AdminService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ProjectController extends Controller
{
    public function __construct(private AdminService $admin) {}

    public function index()
    {
        return view('admin.projects.index', ['projects' => $this->admin->projects()]);
    }

    public function create()
    {
        return view('admin.projects.form', ['project' => new Project(['status' => 'active'])]);
    }

    public function store(Request $request)
    {
        $this->admin->createProject($this->validated($request, null));

        return redirect()->route('admin.projects.index')->with('ok', '專案已建立。');
    }

    public function edit(Project $project)
    {
        return view('admin.projects.form', ['project' => $project]);
    }

    public function update(Request $request, Project $project)
    {
        $this->admin->updateProject($project, $this->validated($request, $project));

        return redirect()->route('admin.projects.index')->with('ok', '專案已更新。');
    }

    private function validated(Request $request, ?Project $project): array
    {
        return $request->validate([
            'code' => ['required', 'string', 'max:30', Rule::unique('projects', 'code')->ignore($project?->id)],
            'name' => ['required', 'string', 'max:100'],
            // ticket / issue numbers are unique across projects, so the prefix must be too
            'ticket_prefix' => ['required', 'string', 'max:10', Rule::unique('projects', 'ticket_prefix')->ignore($project?->id)],
            'status' => ['required', Rule::in(['active', 'inactive'])],
        ], [], ['code' => '專案代碼', 'name' => '專案名稱', 'ticket_prefix' => '單號前綴', 'status' => '狀態']);
    }
}
