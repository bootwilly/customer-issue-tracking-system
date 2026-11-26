<?php

namespace App\Http\Middleware;

use App\Support\CurrentProject;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserActive
{
    public function __construct(private CurrentProject $current) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && ! $user->isActive()) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('home')->with('err', '此帳號已停用，請聯絡系統管理員。');
        }

        // Make `$user->can()` project-aware on every authenticated page.
        $this->current->apply();

        return $next($request);
    }
}
