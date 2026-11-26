<?php

namespace App\Http\Controllers\Notify;

use App\Http\Controllers\Controller;
use App\Services\NotificationService;
use Illuminate\Http\Request;

class AccountSettingsController extends Controller
{
    public function __construct(private NotificationService $svc) {}

    public function edit()
    {
        return view('notify.account');
    }

    public function update(Request $request)
    {
        $this->svc->setEmailOptIn($request->user(), $request->boolean('email_notify'));

        return redirect()->route('account.settings')->with('ok', '個人設定已儲存。');
    }
}
