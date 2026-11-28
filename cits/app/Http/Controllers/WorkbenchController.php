<?php

namespace App\Http\Controllers;

use App\Services\WorkbenchService;
use App\Support\CurrentProject;
use Illuminate\Http\Request;
use Illuminate\View\View;

class WorkbenchController extends Controller
{
    public function __invoke(Request $request, CurrentProject $project, WorkbenchService $service): View
    {
        $tab = $request->query('tab');
        $tab = is_string($tab) && isset(WorkbenchService::TABS[$tab]) ? $tab : 'todo';

        return view('workbench', $service->dashboard($request->user(), $project->id(), $tab, max(1, $request->integer('page', 1))));
    }
}
