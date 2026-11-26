<?php

namespace App\Http\Controllers\Account;

use App\Exceptions\BusinessException;
use App\Http\Controllers\Controller;
use App\Services\ApiTokenService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ApiTokenController extends Controller
{
    public function __construct(private ApiTokenService $tokens) {}

    public function index(Request $request)
    {
        $this->guard($request);

        return view('account.tokens', [
            'tokens' => $this->tokens->listFor($request->user()),
            'expiryChoices' => ApiTokenService::EXPIRY_CHOICES,
            'plain' => session('plain_token'),
        ]);
    }

    public function store(Request $request)
    {
        $this->guard($request);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'expiry' => ['required', Rule::in(ApiTokenService::EXPIRY_CHOICES)],
        ], [], ['name' => '名稱', 'expiry' => '有效期限']);

        $r = $this->tokens->create($request->user(), $data['name'], $data['expiry']);

        return redirect()->route('account.tokens')->with('plain_token', $r['plain'])->with('ok', '權杖已建立，請立即複製，離開後將無法再次顯示。');
    }

    public function revoke(Request $request, int $id)
    {
        $this->guard($request);
        try {
            $this->tokens->revoke($request->user(), $id);
        } catch (BusinessException $e) {
            return back()->with('err', $e->getMessage());
        }

        return back()->with('ok', '權杖已撤銷。');
    }

    private function guard(Request $request): void
    {
        abort_unless($this->tokens->allowed($request->user()), 403);
    }
}
