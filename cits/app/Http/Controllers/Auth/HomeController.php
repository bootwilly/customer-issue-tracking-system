<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\AuthService;
use App\Support\CurrentProject;
use Illuminate\Http\Request;

class HomeController extends Controller
{
    public function __invoke(Request $request, AuthService $auth, CurrentProject $project)
    {
        $user = $request->user();

        if (! $user) {
            return view('auth.login', ['canRemember' => $auth->idleMinutes() === 0]);
        }

        return redirect()->route($auth->landingRoute($user, $project->id()));
    }
}
