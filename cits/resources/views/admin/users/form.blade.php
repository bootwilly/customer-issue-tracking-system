@extends('layouts.app')
@section('title', $user->exists ? '編輯使用者' : '新增使用者')
@section('content')
    @include('admin._tabs', ['active' => 'users'])
    @php $input = 'w-full rounded-md border border-slate-300 px-3 py-2 text-sm focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500'; @endphp
    <div class="max-w-xl rounded-lg bg-white p-6 shadow-sm">
        <h1 class="mb-4 text-xl font-semibold">{{ $user->exists ? '編輯使用者' : '新增使用者' }}</h1>
        <form method="POST" action="{{ $user->exists ? route('admin.users.update', $user) : route('admin.users.store') }}" class="space-y-4">
            @csrf
            @if ($user->exists) @method('PUT') @endif
            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <label class="mb-1 block text-sm font-medium">帳號</label>
                    <input name="account" value="{{ old('account', $user->account) }}" required class="{{ $input }}">
                </div>
                <div>
                    <label class="mb-1 block text-sm font-medium">姓名</label>
                    <input name="name" value="{{ old('name', $user->name) }}" required class="{{ $input }}">
                </div>
            </div>
            <div>
                <label class="mb-1 block text-sm font-medium">Email</label>
                <input type="email" name="email" value="{{ old('email', $user->email) }}" required class="{{ $input }}">
            </div>
            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <label class="mb-1 block text-sm font-medium">使用者類型</label>
                    <select name="user_type" class="{{ $input }}">
                        @foreach (['client' => '客戶', 'internal' => '內部'] as $v => $l)
                            <option value="{{ $v }}" @selected(old('user_type', $user->user_type) === $v)>{{ $l }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="mb-1 block text-sm font-medium">狀態</label>
                    <select name="status" class="{{ $input }}">
                        @foreach (['active' => '啟用', 'disabled' => '停用'] as $v => $l)
                            <option value="{{ $v }}" @selected(old('status', $user->status) === $v)>{{ $l }}</option>
                        @endforeach
                    </select>
                </div>
            </div>
            <div>
                <label class="mb-1 block text-sm font-medium">{{ $user->exists ? '重設密碼（留空則不變更）' : '密碼' }}（至少 {{ $minLength }} 碼）</label>
                <input type="password" name="password" autocomplete="new-password" @required(! $user->exists) class="{{ $input }}">
            </div>
            <label class="flex items-center gap-2 text-sm">
                <input type="hidden" name="is_admin" value="0">
                <input type="checkbox" name="is_admin" value="1" @checked(old('is_admin', $user->is_admin)) class="rounded border-slate-300"> 系統管理員
            </label>
            <div class="flex gap-3">
                <button type="submit" class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700">儲存</button>
                <a href="{{ route('admin.users.index') }}" class="rounded-md bg-slate-100 px-4 py-2 text-sm text-slate-700 hover:bg-slate-200">取消</a>
            </div>
        </form>
    </div>
@endsection
