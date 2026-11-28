@if ($pendingClose->isNotEmpty())
    <section class="mb-4 rounded-lg border border-amber-200 bg-amber-50 p-4">
        <h2 class="font-semibold">待收尾</h2>
        <ul class="mt-2 space-y-1">
            @foreach ($pendingClose as $task)
                <li><a class="text-indigo-700 underline" href="{{ route('issues.show', $task->issue_id) }}#close-tasks">{{ $task->issue->issue_no }} {{ $task->issue->title }}</a> · {{ $task->issue->close_reason }}</li>
            @endforeach
        </ul>
    </section>
@endif
