<?php

namespace App\Http\Controllers\Revision;

use App\Exceptions\BusinessException;
use App\Http\Controllers\Controller;
use App\Services\RevisionDiffService;
use App\Support\CurrentProject;
use Illuminate\Http\Request;

class RevisionController extends Controller
{
    public function __construct(private RevisionDiffService $svc, private CurrentProject $project) {}

    public function diff(Request $r, int $id, int $rev)
    {
        $pid = (int) $this->project->id();
        abort_unless($this->svc->canView($r->user(), $pid), 403);
        try {
            $data = $this->svc->compare($r->user(), $pid, $id, $rev);
        } catch (BusinessException) {
            abort(404);
        }

        return view('revisions.diff', $data);
    }
}
