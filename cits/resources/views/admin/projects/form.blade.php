@extends('layouts.app')
@section('title', $project->exists ? '編輯專案' : '新增專案')
@section('content')
    @include('admin._tabs', ['active' => 'projects'])
    @php $input = 'w-full rounded-md border border-slate-300 px-3 py-2 text-sm focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500'; @endphp
    <div class="max-w-xl rounded-lg bg-white p-6 shadow-sm">
        <h1 class="mb-4 text-xl font-semibold">{{ $project->exists ? '編輯專案' : '新增專案' }}</h1>
        <form method="POST" action="{{ $project->exists ? route('admin.projects.update', $project) : route('admin.projects.store') }}" class="space-y-4">
            @csrf
            @if ($project->exists) @method('PUT') @endif
            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <label class="mb-1 block text-sm font-medium">專案代碼</label>
                    <input name="code" value="{{ old('code', $project->code) }}" required class="{{ $input }}">
                </div>
                <div>
                    <label class="mb-1 block text-sm font-medium">單號前綴</label>
                    <input name="ticket_prefix" value="{{ old('ticket_prefix', $project->ticket_prefix) }}" required class="{{ $input }}">
                </div>
            </div>
            <div>
                <label class="mb-1 block text-sm font-medium">專案名稱</label>
                <input name="name" value="{{ old('name', $project->name) }}" required class="{{ $input }}">
            </div>
            <div>
                <label class="mb-1 block text-sm font-medium">狀態</label>
                <select name="status" class="{{ $input }}">
                    @foreach (['active' => '啟用', 'inactive' => '停用'] as $v => $l)
                        <option value="{{ $v }}" @selected(old('status', $project->status) === $v)>{{ $l }}</option>
                    @endforeach
                </select>
            </div>
            <div class="flex gap-3">
                <button type="submit" class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700">儲存</button>
                <a href="{{ route('admin.projects.index') }}" class="rounded-md bg-slate-100 px-4 py-2 text-sm text-slate-700 hover:bg-slate-200">取消</a>
            </div>
        </form>
    </div>
@endsection
