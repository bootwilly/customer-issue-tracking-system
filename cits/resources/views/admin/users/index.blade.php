@extends('layouts.app')
@section('title', '使用者管理')
@section('content')
    @include('admin._tabs', ['active' => 'users'])
    <div class="mb-4 flex items-center justify-between">
        <h1 class="text-xl font-semibold">使用者</h1>
        <a href="{{ route('admin.users.create') }}" class="rounded-md bg-indigo-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-indigo-700">新增使用者</a>
    </div>
    <div class="overflow-x-auto rounded-lg bg-white shadow-sm">
        <table class="min-w-full divide-y divide-slate-200 text-sm">
            <thead class="bg-slate-50 text-left text-xs uppercase text-slate-500">
                <tr>
                    <th class="px-4 py-2">帳號</th><th class="px-4 py-2">姓名</th><th class="px-4 py-2">Email</th>
                    <th class="px-4 py-2">類型</th><th class="px-4 py-2">狀態</th><th class="px-4 py-2">最後登入</th><th class="px-4 py-2"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @foreach ($users as $u)
                    <tr>
                        <td class="px-4 py-2 font-medium">{{ $u->account }} @if ($u->is_admin)<span class="ml-1 rounded bg-amber-100 px-1.5 text-xs text-amber-800">管理員</span>@endif</td>
                        <td class="px-4 py-2">{{ $u->name }}</td>
                        <td class="px-4 py-2">{{ $u->email }}</td>
                        <td class="px-4 py-2">{{ $u->isClient() ? '客戶' : '內部' }}</td>
                        <td class="px-4 py-2">
                            <span class="rounded-full px-2 py-0.5 text-xs {{ $u->isActive() ? 'bg-emerald-100 text-emerald-800' : 'bg-slate-200 text-slate-600' }}">{{ $u->isActive() ? '啟用' : '停用' }}</span>
                        </td>
                        <td class="px-4 py-2 text-slate-500">{{ tw($u->last_login_at) ?: '-' }}</td>
                        <td class="flex gap-3 px-4 py-2 text-right">
                            <a href="{{ route('admin.users.edit', $u) }}" class="text-indigo-600 hover:underline">編輯</a>
                            <form method="POST" action="{{ route('admin.users.destroy', $u) }}" onsubmit="return confirm('確定刪除此使用者？')">
                                @csrf @method('DELETE')
                                <button type="submit" class="text-red-600 hover:underline">刪除</button>
                            </form>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@endsection
