@extends('layouts.app')
@section('title', '測試管理')
@section('content')
    @php
        $tabs = ['active' => '測試與上線', 'bounced' => '已退回/新發現'];
        $keep = ['f' => 1, 'mine' => $mine ? 1 : null, 'status' => $status, 'q' => $q ?: null];
    @endphp
    <div class="mb-4 flex flex-wrap items-center justify-between gap-2">
        <h1 class="text-xl font-semibold">測試管理</h1>
        @if ($canManage)
            <a href="{{ route('test.issues.create') }}" class="rounded-md bg-indigo-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-indigo-700">新增測試異常</a>
        @endif
    </div>

    <div class="mb-4 flex flex-wrap gap-1 border-b border-slate-200">
        @foreach ($tabs as $k => $label)
            <a href="{{ route('test.index', array_filter(['tab' => $k] + $keep, fn ($v) => $v !== null)) }}"
               class="-mb-px border-b-2 px-4 py-2 text-sm {{ $tab === $k ? 'border-indigo-600 font-medium text-indigo-700' : 'border-transparent text-slate-600 hover:text-slate-900' }}">{{ $label }}</a>
        @endforeach
    </div>

    @include('collab._saved_queries', ['scope' => 'test'])

    <form method="GET" action="{{ route('test.index') }}" class="mb-4 flex flex-wrap items-end gap-3 rounded-lg bg-white p-3 shadow-sm">
        <input type="hidden" name="tab" value="{{ $tab }}">
        <input type="hidden" name="f" value="1">
        <label class="flex items-center gap-1.5 text-sm"><input type="checkbox" name="mine" value="1" @checked($mine) class="rounded border-slate-300"> 我負責的</label>
        <label class="text-sm">狀態
            <select name="status" class="ml-1 rounded-md border border-slate-300 py-1 text-sm">
                <option value="">全部</option>
                @foreach ($statusOptions as $code => $name)
                    <option value="{{ $code }}" @selected($status === $code)>{{ $name }}</option>
                @endforeach
            </select>
        </label>
        <label class="text-sm">關鍵字
            <input type="text" name="q" value="{{ $q }}" placeholder="單號或標題" class="ml-1 rounded-md border border-slate-300 px-2 py-1 text-sm">
        </label>
        <button type="submit" class="rounded-md bg-slate-800 px-3 py-1.5 text-sm text-white hover:bg-slate-700">篩選</button>
        @if ($canExport ?? false)
            <a href="{{ route('test.export', request()->query()) }}" class="rounded-md border border-slate-300 bg-white px-3 py-1.5 text-sm text-slate-700 hover:bg-slate-50">匯出 Excel</a>
        @endif
    </form>

    @include('dev._table', ['issues' => $issues, 'mode' => 'test'])
@endsection
