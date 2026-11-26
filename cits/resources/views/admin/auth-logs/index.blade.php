@extends('layouts.app')
@section('title', '登入紀錄')
@section('content')
    @include('admin._tabs', ['active' => 'auth-logs'])
    @php $fcls = 'rounded-md border border-slate-300 bg-white px-2 py-1.5 text-sm'; @endphp
    <div class="mb-3 flex items-center justify-between">
        <h1 class="text-xl font-semibold">登入紀錄</h1>
        <div class="flex items-center gap-3 text-sm">
            <span class="text-xs text-slate-500">保留 {{ $keepDays > 0 ? $keepDays.' 天' : '永久' }}</span>
            <a href="{{ route('admin.auth-logs.export', request()->query()) }}" class="rounded-md border border-slate-300 bg-white px-3 py-1.5 hover:bg-slate-50">匯出 Excel</a>
        </div>
    </div>
    <form method="GET" action="{{ route('admin.auth-logs.index') }}" class="mb-3 flex flex-wrap items-center gap-2 text-sm">
        <input type="search" name="user" value="{{ $f['user'] }}" maxlength="100" placeholder="帳號、姓名或輸入的帳號" class="{{ $fcls }} w-52">
        <input type="search" name="ip" value="{{ $f['ip'] }}" maxlength="45" placeholder="IP（開頭符合）" class="{{ $fcls }} w-40">
        <select name="event" class="{{ $fcls }}">
            <option value="">全部事件</option>
            @foreach ($events as $code => $name)<option value="{{ $code }}" @selected($f['event'] === $code)>{{ $name }}</option>@endforeach
        </select>
        <label class="flex items-center gap-1 text-slate-600">日期
            <input type="date" name="from" value="{{ $f['from'] }}" class="{{ $fcls }}"> ～
            <input type="date" name="to" value="{{ $f['to'] }}" class="{{ $fcls }}">
        </label>
        <button type="submit" class="rounded-md bg-slate-100 px-3 py-1.5 hover:bg-slate-200">篩選</button>
        @if (array_filter($f))
            <a href="{{ route('admin.auth-logs.index') }}" class="text-xs text-slate-500 hover:underline">清除條件</a>
        @endif
    </form>
    <div class="overflow-x-auto rounded-lg bg-white shadow-sm">
        @include('admin.auth-logs._table', ['rows' => $logs, 'withUser' => true])
    </div>
    <div class="mt-4">{{ $logs->links() }}</div>
    <p class="mt-2 text-xs text-slate-500">時間為台北時間。IP 由主機前端代理轉送；若和 X-Forwarded-For（灰字）不同，兩個都列出。</p>
@endsection
