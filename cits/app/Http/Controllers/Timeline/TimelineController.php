<?php

namespace App\Http\Controllers\Timeline;

use App\Http\Controllers\Controller;
use App\Services\Timeline\TimelineService;
use App\Support\CurrentProject;
use Illuminate\Http\Request;

class TimelineController extends Controller
{
    public function __construct(private TimelineService $svc, private CurrentProject $project) {}

    public function index(Request $r)
    {
        $pid = (int) $this->project->id();
        abort_unless($this->svc->canView($r->user(), $pid), 403);

        return view('timeline.index', $this->svc->index($r->user(), $pid, $r->query('sort')));
    }
}
