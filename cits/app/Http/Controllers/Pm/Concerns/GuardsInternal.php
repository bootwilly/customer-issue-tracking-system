<?php

namespace App\Http\Controllers\Pm\Concerns;

use App\Exceptions\BusinessException;
use App\Models\User;
use App\Services\WorkflowService;
use App\Support\CurrentProject;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

trait GuardsInternal
{
    protected function pid(): int
    {
        return (int) app(CurrentProject::class)->id();
    }

    /** Internal users only, holding $permission in the current project (admins pass). */
    protected function guard(Request $request, string $permission): User
    {
        $u = $request->user();
        abort_unless($u->seesInternal(), 403);
        abort_unless(app(WorkflowService::class)->can($u, $permission, $this->pid()), 403);

        return $u;
    }

    /** Run a service call; BusinessException -> back with error. A returned redirect is used as the target. */
    protected function act(Closure $fn, string $ok): RedirectResponse
    {
        try {
            $res = $fn();
        } catch (BusinessException $e) {
            return back()->with('err', $e->getMessage())->withInput();
        }

        return ($res instanceof RedirectResponse ? $res : back())->with('ok', $ok);
    }
}
