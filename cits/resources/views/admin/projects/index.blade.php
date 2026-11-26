@extends('layouts.app')
@section('title', '專案管理')
@section('content')
    @include('admin._tabs', ['active' => 'projects'])
    <div class="mb-4 flex items-center justify-between">
        <h1 class="text-xl font-semibold">專案</h1>
        <a href="{{ route('admin.projects.create') }}" class="rounded-md bg-indigo-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-indigo-700">新增專案</a>
    </div>
    @if ($projects->isEmpty())
        <x-empty>尚未建立任何專案。</x-empty>
    @else
        <div class="overflow-x-auto rounded-lg bg-white shadow-sm">
            <table class="min-w-full divide-y divide-slate-200 text-sm">
                <thead class="bg-slate-50 text-left text-xs uppercase text-slate-500">
                    <tr><th class="px-4 py-2">代碼</th><th class="px-4 py-2">名稱</th><th class="px-4 py-2">單號前綴</th><th class="px-4 py-2">狀態</th><th class="px-4 py-2"></th></tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @foreach ($projects as $p)
                        <tr>
                            <td class="px-4 py-2 font-medium">{{ $p->code }}</td>
                            <td class="px-4 py-2">{{ $p->name }}</td>
                            <td class="px-4 py-2">{{ $p->ticket_prefix }}</td>
                            <td class="px-4 py-2"><span class="rounded-full px-2 py-0.5 text-xs {{ $p->status === 'active' ? 'bg-emerald-100 text-emerald-800' : 'bg-slate-200 text-slate-600' }}">{{ $p->status === 'active' ? '啟用' : '停用' }}</span></td>
                            <td class="flex gap-3 px-4 py-2 text-right">
                                <a href="{{ route('admin.projects.members', $p) }}" class="text-indigo-600 hover:underline">成員與角色</a>
                                <a href="{{ route('admin.projects.edit', $p) }}" class="text-indigo-600 hover:underline">編輯</a>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
@endsection
