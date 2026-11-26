{{-- Shared issue table. Vars: $issues (paginator), $mode = dev|test, $dueIds (dev only) --}}
@php
    $today = now('Asia/Taipei')->toDateString();
    $isTest = ($mode ?? 'dev') === 'test';
    $dueIds = $dueIds ?? [];
    $originLabels = app(\App\Services\WorkflowService::class)->lookupMapAll('issue_origin');
@endphp
@if ($issues->isEmpty())
    <x-empty>沒有符合條件的問題。</x-empty>
@else
    <div class="overflow-x-auto rounded-lg bg-white shadow-sm">
        <table class="min-w-full divide-y divide-slate-200 text-sm">
            <thead class="bg-slate-50 text-left text-xs text-slate-500">
                <tr>
                    <th class="px-3 py-2">單號</th><th class="px-3 py-2">標題</th><th class="px-3 py-2">來源</th>
                    <th class="px-3 py-2">嚴重度</th><th class="px-3 py-2">難易度</th><th class="px-3 py-2">狀態</th>
                    @if ($isTest)
                        <th class="px-3 py-2">排程測試日</th><th class="px-3 py-2">預計上線日</th>
                    @else
                        <th class="px-3 py-2">預計完工日</th><th class="px-3 py-2">退回</th>
                    @endif
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @foreach ($issues as $i)
                    @php $late = $i->current_due && $i->current_due->toDateString() < $today && ! $i->released_at; @endphp
                    <tr class="hover:bg-slate-50">
                        <td class="whitespace-nowrap px-3 py-2"><a href="{{ url('/issues/'.$i->id) }}" class="font-medium text-indigo-600 hover:underline">{{ $i->issue_no }}</a></td>
                        <td class="px-3 py-2">{{ $i->title }}</td>
                        <td class="whitespace-nowrap px-3 py-2">
                            @if ($i->origin === 'test')
                                <span class="rounded bg-rose-100 px-1.5 py-0.5 text-xs text-rose-700">新發現</span>
                            @else
                                <span class="text-xs text-slate-500">{{ $originLabels[$i->origin] ?? $i->origin }}</span>
                            @endif
                        </td>
                        <td class="px-3 py-2">{{ $i->severity ?? '-' }}</td>
                        <td class="px-3 py-2">{{ $i->difficulty ?? '-' }}</td>
                        <td class="whitespace-nowrap px-3 py-2"><x-badge :status="$i->status" scope="issue" /></td>
                        @if ($isTest)
                            <td class="whitespace-nowrap px-3 py-2">{{ $i->test_scheduled_at?->toDateString() ?? '-' }}</td>
                            <td class="whitespace-nowrap px-3 py-2">{{ $i->release_planned_at?->toDateString() ?? '-' }}</td>
                        @else
                            <td class="whitespace-nowrap px-3 py-2 {{ $late ? 'font-medium text-red-600' : '' }}">
                                {{ $i->current_due?->toDateString() ?? '-' }}
                                @if (in_array($i->id, $dueIds, true))
                                    <span class="ml-1 rounded bg-amber-100 px-1.5 py-0.5 text-xs text-amber-800">延期申請中</span>
                                @endif
                            </td>
                            <td class="px-3 py-2">{{ $i->rework_count }}</td>
                        @endif
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    <div class="mt-3">{{ $issues->links() }}</div>
@endif
