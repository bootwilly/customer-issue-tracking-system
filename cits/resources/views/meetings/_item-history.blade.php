{{-- Same internal issue discussed in other meetings (internal users only; $others from MeetingService::itemHistory). --}}
@if ($others->isNotEmpty())
    <details class="mt-2 pl-8 text-sm">
        <summary class="cursor-pointer text-xs text-indigo-700">歷次討論（{{ $others->count() }}）</summary>
        <ol class="mt-1 space-y-2 border-l border-slate-200 pl-3">
            @foreach ($others as $o)
                <li>
                    <a href="{{ route('meetings.show', $o->meeting_id) }}#item-{{ $o->id }}" class="text-xs font-medium text-indigo-700 hover:underline">{{ tw($o->meeting?->start_at, 'Y-m-d') }} {{ $o->meeting?->title }}</a>
                    <x-badge scope="meeting_item" :status="$o->status" class="ml-1" />
                    @if ($o->discussion)<div class="mt-1 whitespace-pre-line text-xs text-slate-600">{{ $o->discussion }}</div>@endif
                    @if ($o->conclusion)<div class="mt-1 whitespace-pre-line text-slate-800">{{ $o->conclusion }}</div>@endif
                    @if ($o->owner || $o->due_date)<div class="mt-1 text-xs text-slate-500">{{ $o->owner?->name }} {{ $o->due_date?->format('Y-m-d') }}</div>@endif
                    <div class="-ml-8">@include('meetings._item-images', ['editable' => false, 'list' => $historyImages->get($o->id, collect())])</div>
                </li>
            @endforeach
        </ol>
    </details>
@endif
