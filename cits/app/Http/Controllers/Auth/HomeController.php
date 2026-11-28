<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\AuthService;
use Illuminate\Http\Request;

class HomeController extends Controller
{
    public function __invoke(Request $request, AuthService $auth)
    {
        $user = $request->user();

        if (! $user) {
            return view('auth.login', ['canRemember' => $auth->idleMinutes() === 0]);
        }

        return redirect()->route('workbench');
    }
}
