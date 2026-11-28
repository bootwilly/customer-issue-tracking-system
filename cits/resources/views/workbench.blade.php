@extends('layouts.app')
@section('title', '我的工作台')
@section('content')
    <h1 class="mb-4 text-xl font-semibold">我的工作台</h1>
    @if ($shortcuts)
        <section class="mb-4 flex flex-wrap items-center gap-2" aria-label="我的常用查詢">
            <h2 class="mr-1 text-sm font-semibold">我的常用查詢</h2>
            @foreach ($shortcuts as $s)
                <a class="rounded border bg-white px-3 py-1 text-sm text-indigo-700 hover:underline" href="{{ $s['url'] }}">{{ $s['module'] }} · {{ $s['name'] }}</a>
            @endforeach
        </section>
    @endif
    <section class="rounded-lg border border-slate-200 bg-white p-4">
        <nav class="mb-4 flex flex-wrap gap-2" aria-label="工作清單">
            @foreach ($tabs as $key => $name)
                <a href="{{ route('workbench', ['tab' => $key]) }}" @if ($key === $tab) aria-current="page" @endif
                   class="rounded-md px-3 py-1.5 text-sm {{ $key === $tab ? 'bg-indigo-600 text-white' : 'bg-slate-100 text-slate-700 hover:bg-indigo-50' }}">
                    {{ $name }} <span class="ml-1 tabular-nums">{{ $counts[$key] }}</span>
                </a>
            @endforeach
        </nav>
        @if ($rows->isEmpty())
            <p class="py-6 text-sm text-slate-500">{{ ['todo' => '目前沒有指派給你的待辦。', 'team' => '目前沒有可由你接手的團隊工作。', 'schedule' => '目前沒有已排日期的工作。', 'watch' => '目前沒有追蹤項目，可在單據明細點選「☆ 關注」。'][$tab] }}</p>
        @else
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead class="bg-slate-50 text-slate-600"><tr>
                        <th class="p-2">{{ $tab === 'watch' ? '類型' : '工作' }}</th><th class="p-2">單號或會議／標題</th>
                        <th class="p-2">狀態</th><th class="p-2">{{ $tab === 'watch' ? '最近更新' : '工作日期' }}</th>
                    </tr></thead>
                    <tbody class="divide-y">
                        @foreach ($rows as $row)
                            <tr>
                                <td class="whitespace-nowrap p-2">{{ $row->label }}</td>
                                <td class="p-2"><a class="text-indigo-700 hover:underline" href="{{ $row->url }}"><span class="block text-xs">{{ $row->reference }}</span>{{ $row->title }}</a></td>
                                <td class="whitespace-nowrap p-2">{{ $row->statusName }}</td>
                                <td class="whitespace-nowrap p-2">
                                    @if ($tab === 'watch')
                                        {{ tw($row->activity_at) }}
                                    @elseif ($row->work_date)
                                        <span class="block text-xs text-slate-500">{{ $row->dateLabel }}</span>
                                        {{ $row->work_date }}
                                        <span class="{{ $row->dateFlag === '已逾期' ? 'text-red-700' : 'text-indigo-700' }}">{{ $row->dateFlag }}</span>
                                    @else
                                        —
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            {{ $rows->links() }}
        @endif
    </section>
@endsection
