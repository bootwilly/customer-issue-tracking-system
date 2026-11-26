@extends('layouts.app')
@section('title', '客戶問題')
@section('content')
    <div class="mb-4 flex flex-wrap items-center justify-between gap-2">
        <h1 class="text-xl font-semibold">客戶問題</h1>
        <div class="flex items-center gap-2">
            @if ($canExport)
                <a href="{{ route('tickets.export', request()->query()) }}" class="rounded-md border border-slate-300 bg-white px-3 py-1.5 text-sm text-slate-700 hover:bg-slate-50">匯出 Excel</a>
            @endif
            @if ($canCreate)
                <a href="{{ route('tickets.create') }}" class="rounded-md bg-indigo-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-indigo-700">開立新單</a>
            @endif
        </div>
    </div>

    <div class="mb-3 flex flex-wrap gap-1 border-b border-slate-200">
        @foreach ($tabs as $key => $label)
            <a href="{{ route('tickets.index', array_merge(request()->except('page'), ['tab' => $key])) }}"
               class="-mb-px border-b-2 px-4 py-2 text-sm {{ $tab === $key ? 'border-indigo-600 font-medium text-indigo-700' : 'border-transparent text-slate-600 hover:text-slate-900' }}">
                {{ $label }} <span class="ml-1 rounded-full bg-slate-100 px-2 text-xs text-slate-600">{{ $counts[$key] ?? 0 }}</span>
            </a>
        @endforeach
    </div>

    @include('collab._saved_queries', ['scope' => 'client'])

    <form method="GET" action="{{ route('tickets.index') }}" class="mb-4 grid grid-cols-2 gap-2 rounded-lg bg-white p-3 shadow-sm md:flex md:flex-wrap md:items-end">
        <input type="hidden" name="tab" value="{{ $tab }}">
        <label class="text-xs text-slate-500">類型
            <select name="type" class="mt-1 block w-full rounded-md border border-slate-300 py-1 text-sm">
                <option value="">全部</option>
                @foreach ($types as $code => $name)
                    <option value="{{ $code }}" @selected($filters['type'] === $code)>{{ $name }}</option>
                @endforeach
            </select>
        </label>
        <label class="col-span-2 text-xs text-slate-500 md:col-span-1">關鍵字
            <input type="text" name="q" value="{{ $filters['q'] }}" placeholder="單號或標題" class="mt-1 block w-full rounded-md border border-slate-300 py-1 text-sm">
        </label>
        <label class="text-xs text-slate-500">排序
            <select name="sort" class="mt-1 block w-full rounded-md border border-slate-300 py-1 text-sm">
                <option value="last" @selected($filters['sort'] === 'last')>最後更新</option>
                <option value="submitted" @selected($filters['sort'] === 'submitted')>開立時間</option>
                <option value="no" @selected($filters['sort'] === 'no')>單號</option>
            </select>
        </label>
        <label class="text-xs text-slate-500">方向
            <select name="dir" class="mt-1 block w-full rounded-md border border-slate-300 py-1 text-sm">
                <option value="desc" @selected($filters['dir'] === 'desc')>新到舊</option>
                <option value="asc" @selected($filters['dir'] === 'asc')>舊到新</option>
            </select>
        </label>
        <label class="flex items-center gap-1 text-sm text-slate-600"><input type="checkbox" name="mine" value="1" @checked($filters['mine'])> 只看我開的</label>
        <label class="flex items-center gap-1 text-sm text-slate-600"><input type="checkbox" name="ni" value="waiting" @checked($filters['ni'] === 'waiting')> 只看需要補充資料</label>
        <button class="rounded-md bg-slate-800 px-3 py-1.5 text-sm text-white hover:bg-slate-700">篩選</button>
    </form>

    <div class="overflow-x-auto rounded-lg bg-white shadow-sm">
        <table class="min-w-full divide-y divide-slate-200 text-sm">
            <thead class="bg-slate-50 text-left text-xs text-slate-500">
                <tr>
                    @if ($tab === 'open')<th class="w-10 px-3 py-2"></th>@endif
                    <th class="px-3 py-2">單號</th><th class="px-3 py-2">標題</th><th class="px-3 py-2">類型</th>
                    <th class="px-3 py-2">功能頁面</th><th class="px-3 py-2">狀態</th><th class="px-3 py-2">處理進度</th><th class="px-3 py-2">開立者</th>
                    <th class="px-3 py-2">開立時間</th><th class="px-3 py-2">最後更新</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse ($page as $t)
                    @php $ab = $abilities[$t->id] ?? []; @endphp
                    <tr class="cursor-pointer hover:bg-slate-50" onclick="if(!event.target.closest('a,button'))location.href='{{ route('tickets.show', $t->id) }}'">
                        @if ($tab === 'open')
                            <td class="px-3 py-2">
                                @if ($ab['edit'] ?? false)
                                    <a href="{{ route('tickets.edit', $t->id) }}" title="編輯" class="text-indigo-600 hover:text-indigo-800">
                                        <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M16.9 3.9a2.1 2.1 0 013 3L8 18.8 3.5 20l1.2-4.5L16.9 3.9z"/></svg>
                                    </a>
                                @else
                                    @php $why = ($ab['chain_locked'] ?? false) ? '已拋單鎖定' : (($ab['locked_by_other'] ?? null) ? $ab['locked_by_other'].' 編輯中' : '無法編輯'); @endphp
                                    <span title="{{ $why }}" class="text-slate-400">
                                        <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="5" y="11" width="14" height="9" rx="2"/><path d="M8 11V8a4 4 0 118 0v3"/></svg>
                                    </span>
                                @endif
                            </td>
                        @endif
                        <td class="whitespace-nowrap px-3 py-2 font-medium"><a href="{{ route('tickets.show', $t->id) }}" class="text-indigo-700 hover:underline">{{ $t->ticket_no }}</a></td>
                        <td class="max-w-xs truncate px-3 py-2">@if (($needinfoStates[$t->id] ?? null) === 'waiting')<span class="mr-1 rounded bg-rose-100 px-1.5 py-0.5 text-xs text-rose-800">需要補充</span>@endif{{ $t->title }}</td>
                        <td class="whitespace-nowrap px-3 py-2">{{ $types[$t->ticket_type] ?? $t->ticket_type }}</td>
                        <td class="whitespace-nowrap px-3 py-2">{{ $t->mesPage?->name ?? '-' }}</td>
                        <td class="px-3 py-2"><x-badge :status="$t->status" scope="ticket" /></td>
                        <td class="whitespace-nowrap px-3 py-2 text-xs text-slate-600">{{ $progress[$t->id]['label'] ?? '-' }}@if ($progress[$t->id]['eta'] ?? null)<span class="block text-slate-400">預計 {{ $progress[$t->id]['eta'] }} 更新</span>@endif</td>
                        <td class="whitespace-nowrap px-3 py-2">{{ $t->creator?->name }}</td>
                        <td class="whitespace-nowrap px-3 py-2 text-slate-500">{{ tw($t->created_at) }}</td>
                        <td class="whitespace-nowrap px-3 py-2 text-slate-500">{{ tw($t->updated_at) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="10" class="px-3 py-8"><x-empty>目前沒有資料</x-empty></td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="mt-4">{{ $page->links() }}</div>
@endsection
