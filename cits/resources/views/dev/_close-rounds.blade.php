@if ($rounds->isEmpty())
    <x-empty>沒有符合條件的結案收尾紀錄。</x-empty>
@else
    <div class="overflow-x-auto rounded-lg bg-white shadow-sm">
        <table class="min-w-full divide-y divide-slate-200 text-sm">
            <thead class="bg-slate-50 text-left text-xs text-slate-500">
                <tr>@foreach ($closeColumns as $heading)<th class="whitespace-nowrap px-3 py-2">{{ $heading }}</th>@endforeach</tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @foreach ($rounds as $row)
                    <tr class="hover:bg-slate-50">
                        @foreach ($closeColumns as $key => $heading)
                            <td @class(['px-3 py-2', 'whitespace-nowrap' => $key !== 'title' && $key !== 'pending'])>
                                @if ($key === 'issue_no')
                                    <a href="{{ route('issues.show', $row['issue_id']) }}#close-tasks" class="font-medium text-indigo-600 hover:underline">{{ $row[$key] }}</a>
                                @else
                                    {{ $row[$key] }}
                                @endif
                            </td>
                        @endforeach
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    <div class="mt-3">{{ $rounds->links() }}</div>
@endif
