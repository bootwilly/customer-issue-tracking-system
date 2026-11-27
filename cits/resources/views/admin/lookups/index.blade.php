@extends('layouts.app')
@section('title', '選項管理')
@section('content')
    @include('admin._tabs', ['active' => 'lookups'])
    <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
        <div class="flex items-center gap-2">
            <h1 class="text-xl font-semibold">選項管理</h1>
            <form method="GET" onsubmit="location.href='{{ url('admin/lookups') }}/'+this.category.value; return false;">
                <select name="category" onchange="this.form.requestSubmit()" class="rounded-md border border-slate-300 py-1 pl-2 pr-8 text-sm">
                    @foreach ($categories as $code => $label)
                        <option value="{{ $code }}" @selected($code === $category)>{{ $label }}</option>
                    @endforeach
                </select>
            </form>
        </div>
        <form method="POST" action="{{ route('admin.lookups.store', $category) }}" class="flex gap-2">
            @csrf
            <input type="text" name="name" value="{{ old('name') }}" maxlength="50" required placeholder="新項目名稱"
                   class="w-48 rounded-md border border-slate-300 px-3 py-1.5 text-sm">
            <button type="submit" class="rounded-md bg-indigo-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-indigo-700">＋ 新增</button>
        </form>
    </div>

    <p class="mb-3 text-xs text-slate-500">
        停用＝新資料選不到，舊資料仍顯示原名稱。改名會讓所有舊資料一起顯示新名稱；要保留舊名稱請停用舊的、另外新增。有資料使用中的項目不能刪除。
    </p>

    <div class="overflow-x-auto rounded-lg bg-white shadow-sm">
        <table class="min-w-full divide-y divide-slate-200 text-sm">
            <thead class="bg-slate-50 text-left text-xs text-slate-500">
                <tr><th class="px-3 py-2">順序</th><th class="px-3 py-2">名稱</th><th class="px-3 py-2">使用中</th><th class="px-3 py-2">狀態</th><th class="px-3 py-2"></th></tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse ($items as $it)
                    <tr class="{{ $it->is_active ? '' : 'bg-slate-50 text-slate-400' }}">
                        <td class="whitespace-nowrap px-3 py-2">
                            @foreach (['up' => '↑', 'down' => '↓'] as $dir => $arrow)
                                <form method="POST" action="{{ route('admin.lookups.move', [$category, $it->id]) }}" class="inline">
                                    @csrf
                                    <input type="hidden" name="dir" value="{{ $dir }}">
                                    <button type="submit" class="rounded px-1.5 text-slate-500 hover:bg-slate-100 disabled:opacity-30"
                                            @disabled(($dir === 'up' && $loop->parent->first) || ($dir === 'down' && $loop->parent->last))>{{ $arrow }}</button>
                                </form>
                            @endforeach
                        </td>
                        <td class="px-3 py-2">
                            <form method="POST" action="{{ route('admin.lookups.update', [$category, $it->id]) }}" class="flex gap-1">
                                @csrf @method('PUT')
                                <input type="text" name="name" value="{{ $it->name }}" maxlength="50" required class="w-48 rounded border border-slate-300 px-2 py-1 text-sm">
                                <button type="submit" class="rounded bg-slate-100 px-2 text-xs hover:bg-slate-200">改名</button>
                            </form>
                        </td>
                        <td class="px-3 py-2">{{ $it->usage }} 筆</td>
                        <td class="px-3 py-2">
                            <span class="rounded-full px-2 py-0.5 text-xs {{ $it->is_active ? 'bg-emerald-100 text-emerald-800' : 'bg-slate-200 text-slate-600' }}">{{ $it->is_active ? '可選' : '不可選' }}</span>
                        </td>
                        <td class="whitespace-nowrap px-3 py-2">
                            <form method="POST" action="{{ route('admin.lookups.toggle', [$category, $it->id]) }}" class="inline">
                                @csrf
                                <button type="submit" class="text-sm text-indigo-700 hover:underline">{{ $it->is_active ? '停用' : '啟用' }}</button>
                            </form>
                            @if ($it->usage === 0)
                                <form method="POST" action="{{ route('admin.lookups.destroy', [$category, $it->id]) }}" class="ml-3 inline" onsubmit="return confirm(@js('確定刪除「'.$it->name.'」？'))">
                                    @csrf @method('DELETE')
                                    <button type="submit" class="text-sm text-red-600 hover:underline">刪除</button>
                                </form>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="px-3 py-6 text-center text-slate-500">還沒有項目。</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
@endsection
