<?php

namespace App\Http\Controllers\Account;

use App\Http\Controllers\Controller;
use App\Services\AuthLogService;
use App\Services\AuthService;
use Illuminate\Http\Request;

class PasswordController extends Controller
{
    public function __construct(private AuthService $auth, private AuthLogService $authLog) {}

    public function edit()
    {
        return view('account.password', ['minLength' => $this->auth->passwordMinLength()]);
    }

    public function update(Request $request)
    {
        $min = $this->auth->passwordMinLength();
        $data = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'string', "min:{$min}", 'confirmed'],
        ], [], ['current_password' => '目前密碼', 'password' => '新密碼']);

        $result = $this->auth->changePassword($request->user(), $data['current_password'], $data['password']);

        if (! $result['ok']) {
            $this->authLog->record($request, AuthLogService::PASSWORD_CHANGED, $request->user(), null, 'wrong_password');

            return back()->with('err', $result['message']);
        }
        $this->authLog->record($request, AuthLogService::PASSWORD_CHANGED, $request->user());

        return back()->with('ok', '密碼已更新。');
    }
}
