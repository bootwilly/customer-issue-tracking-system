<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Middleware\LogoutWhenIdle;
use App\Services\AuthService;
use App\Support\CurrentProject;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class LoginController extends Controller
{
    public function __construct(private AuthService $auth, private CurrentProject $current) {}

    public function store(Request $request)
    {
        $data = $request->validate([
            'login' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string'],
        ], [], ['login' => '帳號或 Email', 'password' => '密碼']);

        $result = $this->auth->attempt($data['login'], $data['password'], (string) $request->ip(), $request->boolean('remember'));

        if (! $result['ok']) {
            return back()->withInput($request->only('login'))->with('err', $result['message']);
        }

        $request->session()->regenerate();
        $request->session()->put(LogoutWhenIdle::SESSION_KEY, now()->getTimestamp());

        $user = $request->user();
        $only = $this->auth->soleProjectId($user);
        if ($only) {
            $this->current->set($only);

            return redirect()->intended(route('home'));
        }

        return redirect()->route('projects.select');
    }

    /** "Keep me signed in" from the idle warning: the request itself refreshes the idle clock. */
    public function ping()
    {
        return response()->json(['ok' => true]);
    }

    public function destroy(Request $request)
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('home');
    }
}
