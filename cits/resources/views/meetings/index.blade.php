@extends('layouts.app')
@section('title', '會議')
@section('content')
    <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
        <h1 class="text-xl font-semibold">會議</h1>
        <div class="flex flex-wrap items-center gap-2">
            @if ($tab !== 'mine' && $canExport)
                <a href="{{ route('meetings.export', request()->query()) }}" class="rounded-md border border-emerald-600 px-3 py-1.5 text-sm font-medium text-emerald-700 hover:bg-emerald-50"
                   title="依目前的頁籤與篩選條件匯出全部會議（含討論事項與留言）">匯出 Excel</a>
            @endif
            @if ($canCreate)
                <a href="{{ route('meetings.create') }}" class="rounded-md bg-indigo-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-indigo-700">＋ 新增會議</a>
            @endif
        </div>
    </div>

    <nav class="mb-3 flex flex-wrap gap-1 text-sm">
        @foreach ($tabs as $code => $label)
            <a href="{{ route('meetings.index', array_filter(['tab' => $code] + ($code === 'mine' ? [] : ['q' => $q ?: null] + $f))) }}"
               class="rounded-full px-3 py-1 {{ $tab === $code ? 'bg-slate-800 text-white' : 'bg-white text-slate-600 ring-1 ring-slate-200 hover:bg-slate-50' }}">{{ $label }}</a>
        @endforeach
    </nav>

    @if ($tab !== 'mine')
        @php $fcls = 'rounded-md border border-slate-300 bg-white px-2 py-1.5 text-sm'; @endphp
        <form method="GET" action="{{ route('meetings.index') }}" class="mb-3 flex flex-wrap items-center gap-2 text-sm">
            <input type="hidden" name="tab" value="{{ $tab }}">
            <input type="search" name="q" value="{{ $q }}" maxlength="100" placeholder="搜尋主題或紀錄內容" class="{{ $fcls }} w-56">
            <select name="type" class="{{ $fcls }}">
                <option value="">全部類型</option>
                @foreach ($types as $code => $name)<option value="{{ $code }}" @selected($f['type'] === $code)>{{ $name }}</option>@endforeach
            </select>
            <select name="status" class="{{ $fcls }}">
                <option value="">全部狀態</option>
                @foreach ($statuses as $code => $name)<option value="{{ $code }}" @selected($f['status'] === $code)>{{ $name }}</option>@endforeach
            </select>
            <label class="flex items-center gap-1 text-slate-600">日期
                <input type="date" name="from" value="{{ $f['from'] }}" class="{{ $fcls }}"> ～
                <input type="date" name="to" value="{{ $f['to'] }}" class="{{ $fcls }}">
            </label>
            <button type="submit" class="rounded-md bg-slate-100 px-3 py-1.5 hover:bg-slate-200">篩選</button>
            @if ($q !== '' || array_filter($f))
                <a href="{{ route('meetings.index', ['tab' => $tab]) }}" class="text-xs text-slate-500 hover:underline">清除條件</a>
            @endif
        </form>
    @endif

    <div class="overflow-x-auto rounded-lg bg-white shadow-sm">
        @if ($tab === 'mine')
            <table class="min-w-full divide-y divide-slate-200 text-sm">
                <thead class="bg-slate-50 text-left text-xs text-slate-500">
                    <tr><th class="px-3 py-2">期限</th><th class="px-3 py-2">問題點</th><th class="px-3 py-2">結論／解決方法</th><th class="px-3 py-2">會議</th></tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse ($items as $i)
                        @php $late = $i->due_date && $i->due_date->lt(now('Asia/Taipei')->startOfDay()); @endphp
                        <tr>
                            <td class="whitespace-nowrap px-3 py-2 {{ $late ? 'font-medium text-red-600' : '' }}">{{ $i->due_date?->format('Y-m-d') ?? '—' }}@if ($late)（逾期）@endif</td>
                            <td class="px-3 py-2">{{ $i->topic }}</td>
                            <td class="max-w-md whitespace-pre-line px-3 py-2 text-slate-600">{{ $i->conclusion }}</td>
                            <td class="whitespace-nowrap px-3 py-2">
                                <a href="{{ route('meetings.show', $i->meeting_id) }}#item-{{ $i->id }}" class="text-indigo-700 hover:underline">{{ $i->meeting?->title }}</a>
                                <span class="text-xs text-slate-500">{{ tw($i->meeting?->start_at, 'm-d') }}</span>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="px-3 py-6 text-center text-slate-500">沒有你負責、還在追蹤中的事項。</td></tr>
                    @endforelse
                </tbody>
            </table>
        @else
            <table class="min-w-full divide-y divide-slate-200 text-sm">
                <thead class="bg-slate-50 text-left text-xs text-slate-500">
                    <tr><th class="px-3 py-2">時間</th><th class="px-3 py-2">主題</th><th class="px-3 py-2">類型</th><th class="px-3 py-2">狀態</th><th class="px-3 py-2">發起人</th><th class="px-3 py-2">最後更新</th></tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse ($meetings as $m)
                        <tr>
                            <td class="whitespace-nowrap px-3 py-2">{{ tw($m->start_at, 'Y-m-d H:i') }}</td>
                            <td class="px-3 py-2">
                                <a href="{{ route('meetings.show', $m->id) }}" class="font-medium text-indigo-700 hover:underline">{{ $m->title }}</a>
                                @if (auth()->user()->seesInternal())
                                    @if ($m->isClientOrigin())
                                        <span class="ml-1 rounded bg-sky-100 px-1.5 py-0.5 text-xs text-sky-800">客戶發起</span>
                                    @elseif ($m->customer_visible)
                                        <span class="ml-1 rounded bg-emerald-100 px-1.5 py-0.5 text-xs text-emerald-800">開放客戶</span>
                                    @endif
                                @endif
                                @if (($unread[$m->id] ?? 0) > 0)
                                    <span class="ml-1 rounded-full bg-red-600 px-1.5 text-xs font-medium text-white">{{ $unread[$m->id] }} 則新留言</span>
                                @endif
                            </td>
                            <td class="whitespace-nowrap px-3 py-2 text-xs text-slate-500">{{ $svc->typeLabel($m->type) }}</td>
                            <td class="px-3 py-2"><x-badge scope="meeting" :status="$m->status" /></td>
                            <td class="whitespace-nowrap px-3 py-2 text-xs text-slate-500">{{ $m->creator?->name }}</td>
                            <td class="whitespace-nowrap px-3 py-2 text-xs text-slate-500">{{ tw($m->updated_at) }}　{{ $m->editor?->name }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="px-3 py-6 text-center text-slate-500">{{ $q !== '' || array_filter($f) ? '找不到符合條件的會議。' : '目前沒有會議。' }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        @endif
    </div>
@endsection
