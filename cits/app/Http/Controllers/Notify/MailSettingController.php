<?php

namespace App\Http\Controllers\Notify;

use App\Http\Controllers\Controller;
use App\Services\MailSettingService;
use Illuminate\Http\Request;

class MailSettingController extends Controller
{
    public function __construct(private MailSettingService $svc) {}

    public function edit()
    {
        return view('notify.mail', ['s' => $this->svc->current()]);
    }

    public function update(Request $request)
    {
        $data = $request->validate([
            'enabled' => ['nullable', 'boolean'],
            'host' => ['nullable', 'string', 'max:255', 'required_if:enabled,1'],
            'port' => ['required', 'integer', 'between:1,65535'],
            'encryption' => ['required', 'in:none,tls,ssl'],
            'username' => ['nullable', 'string', 'max:255'],
            'password' => ['nullable', 'string', 'max:255'],
            'from_address' => ['nullable', 'email', 'max:255', 'required_if:enabled,1'],
            'from_name' => ['nullable', 'string', 'max:100'],
        ], [], [
            'host' => 'SMTP 主機', 'port' => '連接埠', 'encryption' => '加密方式',
            'from_address' => '寄件人 Email', 'from_name' => '寄件人名稱', 'username' => '帳號',
        ]);
        $data['enabled'] = $request->boolean('enabled');

        $this->svc->update($request->user(), $data, $data['password'] ?? null, $request->boolean('clear_password'));

        return redirect()->route('admin.mail.edit')->with('ok', '郵件設定已儲存。');
    }

    public function test(Request $request)
    {
        $data = $request->validate(['to' => ['required', 'email', 'max:255']], [], ['to' => '測試收件者']);
        $r = $this->svc->sendTest($data['to']);

        return redirect()->route('admin.mail.edit')->with($r['ok'] ? 'ok' : 'err', $r['message']);
    }
}
