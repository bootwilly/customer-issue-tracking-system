<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\AuthService;
use App\Support\CurrentProject;
use Illuminate\Http\Request;

class ProjectSelectController extends Controller
{
    public function __construct(private AuthService $auth, private CurrentProject $current) {}

    public function show(Request $request)
    {
        return view('auth.select-project', [
            'projects' => $this->auth->projectsFor($request->user()),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate(['project_id' => ['required', 'integer']], [], ['project_id' => '專案']);

        if (! $this->auth->canUseProject($request->user(), (int) $data['project_id'])) {
            return redirect()->route('projects.select')->with('err', '您無權使用此專案。');
        }

        $this->current->set((int) $data['project_id']);

        $previous = url()->previous();
        $selectUrl = route('projects.select');
        $isSelect = rtrim(strtok($previous, '?'), '/') === rtrim($selectUrl, '/');

        return $isSelect || ! $previous ? redirect()->intended(route('home')) : redirect($previous);
    }
}
