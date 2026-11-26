<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AdminService;
use App\Services\AuthLogService;
use App\Services\AuthService;
use DomainException;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class UserController extends Controller
{
    public function __construct(private AdminService $admin, private AuthService $auth, private AuthLogService $authLog) {}

    public function index()
    {
        return view('admin.users.index', ['users' => $this->admin->users()]);
    }

    public function create()
    {
        return view('admin.users.form', ['user' => new User(['user_type' => 'internal', 'status' => 'active']), 'minLength' => $this->auth->passwordMinLength()]);
    }

    public function store(Request $request)
    {
        $data = $this->validated($request, null);
        $this->admin->createUser($data);

        return redirect()->route('admin.users.index')->with('ok', '使用者已建立。');
    }

    public function edit(User $user)
    {
        return view('admin.users.form', [
            'user' => $user, 'minLength' => $this->auth->passwordMinLength(),
            'recentLogs' => $this->authLog->recentForUser($user), 'events' => $this->authLog->events(), 'details' => $this->authLog->details(),
        ]);
    }

    public function update(Request $request, User $user)
    {
        $data = $this->validated($request, $user);
        try {
            $this->admin->updateUser($user, $data, $request->user());
        } catch (DomainException $e) {
            return back()->withInput()->with('err', $e->getMessage());
        }

        return redirect()->route('admin.users.index')->with('ok', '使用者已更新。');
    }

    public function destroy(Request $request, User $user)
    {
        try {
            $this->admin->deleteUser($user, $request->user());
        } catch (DomainException $e) {
            return back()->with('err', $e->getMessage());
        }

        return redirect()->route('admin.users.index')->with('ok', '使用者已刪除。');
    }

    private function validated(Request $request, ?User $user): array
    {
        $min = $this->auth->passwordMinLength();

        return $request->validate([
            'account' => ['required', 'string', 'max:50', Rule::unique('users', 'account')->ignore($user?->id)],
            'name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user?->id)],
            'user_type' => ['required', Rule::in(['client', 'internal'])],
            'status' => ['required', Rule::in(['active', 'disabled'])],
            'is_admin' => ['sometimes', 'boolean'],
            'password' => [$user ? 'nullable' : 'required', 'string', "min:{$min}"],
        ], [], [
            'account' => '帳號', 'name' => '姓名', 'email' => 'Email', 'user_type' => '使用者類型',
            'status' => '狀態', 'password' => '密碼',
        ]);
    }
}
