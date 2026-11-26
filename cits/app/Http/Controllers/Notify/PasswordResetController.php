<?php

namespace App\Http\Controllers\Notify;

use App\Http\Controllers\Controller;
use App\Services\PasswordResetService;
use Illuminate\Http\Request;

class PasswordResetController extends Controller
{
    public function __construct(private PasswordResetService $svc) {}

    public function forgot()
    {
        return view('auth.forgot', ['enabled' => $this->svc->mailEnabled()]);
    }

    public function send(Request $request)
    {
        if (! $this->svc->mailEnabled()) {
            return redirect()->route('password.forgot');
        }
        $data = $request->validate(['login' => ['required', 'string', 'max:255']], [], ['login' => '帳號或 Email']);

        if (! $this->svc->request($data['login'], (string) $request->ip())) {
            return back()->with('err', '要求次數過多，請稍後再試。');
        }

        return redirect()->route('password.forgot')->with('ok', '若帳號存在，重設密碼連結已寄至對應的 Email，請於 60 分鐘內使用。');
    }

    public function showReset(Request $request, string $token)
    {
        return view('auth.reset', [
            'token' => $token, 'email' => (string) $request->query('email', ''),
            'enabled' => $this->svc->mailEnabled(), 'minLength' => $this->svc->minLength(),
        ]);
    }

    public function reset(Request $request, string $token)
    {
        $min = $this->svc->minLength();
        $data = $request->validate([
            'email' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string', "min:{$min}", 'confirmed'],
        ], [], ['email' => 'Email', 'password' => '新密碼']);

        if (! $this->svc->reset($data['email'], $token, $data['password'])) {
            return back()->withInput($request->only('email'))->with('err', '重設連結無效或已過期，請重新申請。');
        }

        return redirect()->route('home')->with('ok', '密碼已重設，請使用新密碼登入。');
    }
}
