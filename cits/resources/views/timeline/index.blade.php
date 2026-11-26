@extends('layouts.app')
@section('title', '時序總覽')
@section('content')
    <div class="mb-4 flex flex-wrap items-center justify-between gap-2">
        <h1 class="text-xl font-semibold">時序總覽</h1>
        <form method="GET" action="{{ route('timeline.index') }}" class="flex items-center gap-2 text-sm">
            <label for="sort">排序依據</label>
            <select id="sort" name="sort" onchange="this.form.submit()" class="rounded-md border border-slate-300 py-1 text-sm">
                @foreach ($sortLabels as $k => $label)
                    <option value="{{ $k }}" @selected($sort === $k)>{{ $label }}</option>
                @endforeach
            </select>
        </form>
    </div>

    <div class="mb-4 flex flex-wrap gap-2">
        @foreach ($chips as $label => $n)
            <span class="rounded-full px-3 py-1 text-sm {{ $n > 0 ? 'bg-red-100 text-red-700' : 'bg-slate-100 text-slate-600' }}">{{ $label }} {{ $n }}</span>
        @endforeach
    </div>

    @if (! $groups)
        <x-empty>沒有資料。</x-empty>
    @endif
    @foreach ($groups as $label => $rows)
        <h2 class="mb-2 mt-5 text-sm font-semibold {{ $label === '已逾期' ? 'text-red-600' : 'text-slate-700' }}">{{ $label }}（{{ count($rows) }}）</h2>
        <div class="overflow-x-auto rounded-lg bg-white shadow-sm">
            <table class="min-w-full divide-y divide-slate-200 text-sm">
                <tbody class="divide-y divide-slate-100">
                    @foreach ($rows as $r)
                        <tr class="hover:bg-slate-50 {{ $r['red'] ? 'bg-red-50/60' : '' }}">
                            <td class="whitespace-nowrap px-3 py-2">
                                <a href="{{ $r['url'] }}" class="font-medium text-indigo-600 hover:underline">{{ $r['no'] }}</a>
                                <span class="ml-1 text-xs text-slate-400">{{ $r['kind'] === 'issue' ? '內部' : '客戶' }}</span>
                            </td>
                            <td class="px-3 py-2">{{ $r['title'] }}</td>
                            <td class="whitespace-nowrap px-3 py-2"><x-badge :status="$r['status']" :scope="$r['kind']" /></td>
                            <td class="whitespace-nowrap px-3 py-2 text-slate-600">{{ $r['holder'] }}</td>
                            <td class="whitespace-nowrap px-3 py-2 text-slate-500">{{ $r['date'] }}</td>
                            <td class="whitespace-nowrap px-3 py-2 {{ $r['red'] ? 'font-medium text-red-600' : 'text-slate-500' }}">{{ $r['text'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endforeach
@endsection
