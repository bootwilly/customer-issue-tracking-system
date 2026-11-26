<?php

namespace App\Http\Middleware;

use App\Services\AuthService;
use App\Support\CurrentProject;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureProjectSelected
{
    public function __construct(private CurrentProject $current, private AuthService $auth) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            return redirect()->route('home');
        }

        if (! $user->isActive()) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('home')->with('err', '此帳號已停用，請聯絡系統管理員。');
        }

        $id = $this->current->id();

        if (! $id) {
            $only = $this->auth->soleProjectId($user);
            if ($only) {
                $this->current->set($only);
                $id = $only;
            }
        }

        if (! $id || ! $this->auth->canUseProject($user, $id)) {
            $this->current->forget();

            return redirect()->route('projects.select');
        }

        $this->current->apply();

        return $next($request);
    }
}
