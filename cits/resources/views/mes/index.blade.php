@extends('layouts.app')
@section('title', 'MES 頁面')
@section('content')
    <h1 class="mb-4 text-xl font-semibold">MES 功能頁面註冊</h1>

    @if ($canManage)
    <div class="mb-6 grid gap-4 lg:grid-cols-2">
        <form method="POST" action="{{ route('mes.store') }}" class="space-y-3 rounded-lg bg-white p-4 shadow-sm" x-data="{ type: '{{ old('node_type', 'page') }}' }">
            @csrf
            <h2 class="text-sm font-semibold text-slate-700">新增資料夾 / 頁面</h2>
            <div class="flex gap-4 text-sm">
                <label><input type="radio" name="node_type" value="page" x-model="type"> 頁面</label>
                <label><input type="radio" name="node_type" value="folder" x-model="type"> 資料夾</label>
            </div>
            <input type="text" name="name" value="{{ old('name') }}" required maxlength="100" placeholder="名稱" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm">
            <input type="text" name="page_path" value="{{ old('page_path') }}" x-show="type === 'page'" placeholder="~/Production/WorkOrder.aspx" class="w-full rounded-md border border-slate-300 px-3 py-2 font-mono text-sm">
            <div class="flex gap-3">
                <select name="parent_id" class="flex-1 rounded-md border border-slate-300 px-2 py-2 text-sm">
                    <option value="">（最上層）</option>
                    @foreach ($folders as $f)
                        <option value="{{ $f->id }}" @selected((string) old('parent_id') === (string) $f->id)>{{ str_repeat('　', $f->depth) }}{{ $f->name }}</option>
                    @endforeach
                </select>
                <input type="number" name="sort" value="{{ old('sort', 0) }}" min="0" class="w-24 rounded-md border border-slate-300 px-2 py-2 text-sm" title="排序">
            </div>
            <button type="submit" class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700">新增</button>
        </form>

        <div class="space-y-3 rounded-lg bg-white p-4 shadow-sm">
            <h2 class="text-sm font-semibold text-slate-700">批次匯入</h2>
            <form method="POST" action="{{ route('mes.import.preview') }}" class="space-y-2">
                @csrf
                <textarea name="paths" rows="5" required placeholder="每行一個路徑，例如&#10;~/Production/WorkOrder.aspx" class="w-full rounded-md border border-slate-300 px-3 py-2 font-mono text-sm">{{ old('paths') }}</textarea>
                <button type="submit" class="rounded-md bg-slate-800 px-4 py-2 text-sm text-white hover:bg-slate-700">預覽（不會寫入）</button>
            </form>

            <form method="POST" action="{{ route('mes.sitemap.preview') }}" enctype="multipart/form-data" class="space-y-2 border-t border-slate-100 pt-3">
                @csrf
                <h3 class="text-sm font-medium text-slate-700">Web.sitemap 匯入</h3>
                <input type="file" name="sitemap_file" accept=".sitemap,.xml" class="block w-full text-sm">
                <textarea name="sitemap_xml" rows="4" placeholder="或貼上 Web.sitemap XML（檔案上限 2MB）" class="w-full rounded-md border border-slate-300 px-3 py-2 font-mono text-xs"></textarea>
                <button type="submit" class="rounded-md bg-slate-800 px-4 py-2 text-sm text-white hover:bg-slate-700">預覽 Web.sitemap（不會寫入）</button>
            </form>
        </div>
    </div>
    @endif

    @if ($nodes->isEmpty())
        <x-empty>尚未登錄任何頁面。</x-empty>
    @else
        <div class="overflow-x-auto rounded-lg bg-white shadow-sm">
            <table class="min-w-full divide-y divide-slate-200 text-sm">
                <thead class="bg-slate-50 text-left text-xs text-slate-500">
                    <tr><th class="px-3 py-2">名稱</th><th class="px-3 py-2">路徑</th><th class="px-3 py-2">排序</th><th class="px-3 py-2">狀態</th><th class="px-3 py-2"></th></tr>
                </thead>
                @foreach ($nodes as $n)
                    <tbody x-data="{ edit: false }" class="divide-y divide-slate-100 border-t border-slate-100">
                        <tr class="{{ $n->is_active ? '' : 'text-slate-400' }}">
                            <td class="px-3 py-2" style="padding-left: {{ 0.75 + $n->depth * 1.5 }}rem">
                                {{ $n->name }}@if ($n->node_type === 'folder') <span class="ml-1 rounded bg-slate-100 px-1.5 text-xs text-slate-500">資料夾</span>@endif
                            </td>
                            <td class="px-3 py-2 font-mono text-xs">{{ $n->page_path }}</td>
                            <td class="px-3 py-2">{{ $n->sort }}</td>
                            <td class="px-3 py-2">{{ $n->is_active ? '啟用' : '停用' }}</td>
                            <td class="flex gap-3 whitespace-nowrap px-3 py-2">
                                @if ($canManage)
                                <button type="button" class="text-indigo-600 hover:underline" @click="edit = !edit">編輯</button>
                                <form method="POST" action="{{ route('mes.toggle', $n->id) }}">
                                    @csrf
                                    <button type="submit" class="{{ $n->is_active ? 'text-amber-600' : 'text-emerald-600' }} hover:underline">{{ $n->is_active ? '停用' : '啟用' }}</button>
                                </form>
                                @endif
                            </td>
                        </tr>
                        <tr x-show="edit" x-cloak>
                            <td colspan="5" class="bg-slate-50 px-3 py-3">
                                <form method="POST" action="{{ route('mes.update', $n->id) }}" class="flex flex-wrap items-end gap-2">
                                    @csrf @method('PUT')
                                    <input type="text" name="name" value="{{ $n->name }}" required maxlength="100" class="rounded-md border border-slate-300 px-2 py-1 text-sm">
                                    @if ($n->node_type === 'page')
                                        <input type="text" name="page_path" value="{{ $n->page_path }}" class="w-72 rounded-md border border-slate-300 px-2 py-1 font-mono text-sm">
                                    @endif
                                    <select name="parent_id" class="rounded-md border border-slate-300 px-2 py-1 text-sm">
                                        <option value="">（最上層）</option>
                                        @foreach ($folders->where('id', '!=', $n->id) as $f)
                                            <option value="{{ $f->id }}" @selected($n->parent_id === $f->id)>{{ str_repeat('　', $f->depth) }}{{ $f->name }}</option>
                                        @endforeach
                                    </select>
                                    <input type="number" name="sort" value="{{ $n->sort }}" min="0" class="w-20 rounded-md border border-slate-300 px-2 py-1 text-sm">
                                    <button type="submit" class="rounded-md bg-indigo-600 px-3 py-1 text-sm text-white hover:bg-indigo-700">儲存</button>
                                </form>
                            </td>
                        </tr>
                    </tbody>
                @endforeach
            </table>
        </div>
    @endif
@endsection
