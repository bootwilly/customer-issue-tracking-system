@extends('layouts.app')
@section('title', '報表')
@section('content')
    @php
        $t = $d['tickets'];
        $i = $d['issues'];
        $card = 'rounded-lg bg-white p-4 shadow-sm';
        $th = 'px-3 py-2 text-left text-xs font-medium text-slate-500';
    @endphp
    <div class="mb-4 flex flex-wrap items-center justify-between gap-2">
        <h1 class="text-xl font-semibold">報表</h1>
        <form method="GET" action="{{ route('reports.index') }}" class="flex flex-wrap items-center gap-2 text-sm">
            <label for="from">期間</label>
            <input id="from" type="date" name="from" value="{{ $d['from'] }}" class="rounded-md border border-slate-300 py-1 text-sm">
            <span>～</span>
            <input id="to" type="date" name="to" value="{{ $d['to'] }}" class="rounded-md border border-slate-300 py-1 text-sm">
            <button class="rounded-md bg-indigo-600 px-3 py-1 text-white hover:bg-indigo-700">查詢</button>
            <a href="{{ route('reports.export', ['from' => $d['from'], 'to' => $d['to']]) }}" class="rounded-md border border-slate-300 px-3 py-1 hover:bg-slate-50">匯出 CSV</a>
        </form>
    </div>

    <div class="grid gap-4 lg:grid-cols-2">
        <section class="{{ $card }}">
            <h2 class="mb-3 font-semibold">客戶問題單</h2>
            <div class="mb-3 flex flex-wrap gap-2 text-sm">
                <span class="rounded-full bg-slate-100 px-3 py-1">期間開立 {{ $t['opened'] }}</span>
                <span class="rounded-full bg-slate-100 px-3 py-1">期間結案 {{ $t['closed'] }}</span>
                <span class="rounded-full bg-slate-100 px-3 py-1">平均受理 {{ $t['avgAccept'] ?? '-' }} 天</span>
                <span class="rounded-full bg-slate-100 px-3 py-1">平均結案 {{ $t['avgClose'] ?? '-' }} 天</span>
                <span class="rounded-full px-3 py-1 {{ $t['needsAction'] > 0 ? 'bg-red-100 text-red-700' : 'bg-slate-100' }}">待PM處置 {{ $t['needsAction'] }}</span>
            </div>
            <table class="min-w-full divide-y divide-slate-200 text-sm">
                <thead><tr><th class="{{ $th }}">狀態（目前全部）</th><th class="{{ $th }}">數量</th></tr></thead>
                <tbody class="divide-y divide-slate-100">
                    @foreach ($t['byStatus'] as $r)
                        <tr><td class="px-3 py-1.5">{{ $r['label'] }}</td><td class="px-3 py-1.5">{{ $r['count'] }}</td></tr>
                    @endforeach
                </tbody>
            </table>
        </section>

        <section class="{{ $card }}">
            <h2 class="mb-3 font-semibold">內部問題（期間內建立，共 {{ $i['total'] }}）</h2>
            <div class="grid gap-4 sm:grid-cols-2">
                @foreach ([['依狀態', $i['byStatus']], ['依階段', $i['byStage']], ['依來源', $i['byOrigin']], ['依嚴重度', $i['bySeverity']]] as [$label, $rows])
                    <table class="min-w-full divide-y divide-slate-200 text-sm">
                        <thead><tr><th class="{{ $th }}">{{ $label }}</th><th class="{{ $th }}">數量</th></tr></thead>
                        <tbody class="divide-y divide-slate-100">
                            @forelse ($rows as $r)
                                <tr><td class="px-3 py-1.5">{{ $r['label'] }}</td><td class="px-3 py-1.5">{{ $r['count'] }}</td></tr>
                            @empty
                                <tr><td colspan="2" class="px-3 py-1.5 text-slate-400">無資料</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                @endforeach
            </div>
        </section>
    </div>

    <section class="{{ $card }} mt-4">
        <h2 class="mb-3 font-semibold">逾期問題（預計完工早於 {{ $d['today'] }}，依開發人員）</h2>
        @forelse ($d['overdue'] as $g)
            <h3 class="mb-1 mt-3 text-sm font-semibold text-red-600">{{ $g['name'] }}（{{ $g['count'] }}）</h3>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-slate-200 text-sm">
                    <tbody class="divide-y divide-slate-100">
                        @foreach ($g['issues'] as $x)
                            <tr>
                                <td class="whitespace-nowrap px-3 py-1.5"><a href="{{ $x['url'] }}" class="font-medium text-indigo-600 hover:underline">{{ $x['no'] }}</a></td>
                                <td class="px-3 py-1.5">{{ $x['title'] }}</td>
                                <td class="whitespace-nowrap px-3 py-1.5"><x-badge :status="$x['status']" scope="issue" /></td>
                                <td class="whitespace-nowrap px-3 py-1.5 text-slate-500">{{ $x['due'] }}</td>
                                <td class="whitespace-nowrap px-3 py-1.5 text-red-600">已過 {{ $x['days'] }} 天</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @empty
            <x-empty>沒有逾期問題。</x-empty>
        @endforelse
    </section>

    <section class="{{ $card }} mt-4">
        <h2 class="mb-3 font-semibold">開發退回（期間內建立）</h2>
        <div class="mb-3 flex gap-2 text-sm">
            <span class="rounded-full bg-slate-100 px-3 py-1">退回問題數 {{ $d['rework']['count'] }}</span>
            <span class="rounded-full bg-slate-100 px-3 py-1">平均退回 {{ $d['rework']['avg'] }} 次</span>
        </div>
        @if ($d['rework']['top'])
            <table class="min-w-full divide-y divide-slate-200 text-sm">
                <thead><tr><th class="{{ $th }}">編號</th><th class="{{ $th }}">標題</th><th class="{{ $th }}">狀態</th><th class="{{ $th }}">退回次數</th></tr></thead>
                <tbody class="divide-y divide-slate-100">
                    @foreach ($d['rework']['top'] as $r)
                        <tr>
                            <td class="whitespace-nowrap px-3 py-1.5"><a href="{{ $r['url'] }}" class="font-medium text-indigo-600 hover:underline">{{ $r['no'] }}</a></td>
                            <td class="px-3 py-1.5">{{ $r['title'] }}</td>
                            <td class="whitespace-nowrap px-3 py-1.5"><x-badge :status="$r['status']" scope="issue" /></td>
                            <td class="px-3 py-1.5">{{ $r['rework'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @else
            <x-empty>期間內沒有退回紀錄。</x-empty>
        @endif
    </section>

    <section class="{{ $card }} mt-4">
        <h2 class="mb-3 font-semibold">月趨勢（近 6 個月）</h2>
        <table class="min-w-full divide-y divide-slate-200 text-sm">
            <thead><tr>
                <th class="{{ $th }}">月份</th><th class="{{ $th }}">客戶單開立</th><th class="{{ $th }}">客戶單結案</th>
                <th class="{{ $th }}">問題建立</th><th class="{{ $th }}">問題上線</th>
            </tr></thead>
            <tbody class="divide-y divide-slate-100">
                @foreach ($d['trend'] as $m)
                    <tr>
                        <td class="px-3 py-1.5">{{ $m['month'] }}</td><td class="px-3 py-1.5">{{ $m['tickets_opened'] }}</td>
                        <td class="px-3 py-1.5">{{ $m['tickets_closed'] }}</td><td class="px-3 py-1.5">{{ $m['issues_created'] }}</td>
                        <td class="px-3 py-1.5">{{ $m['issues_released'] }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </section>
@endsection
