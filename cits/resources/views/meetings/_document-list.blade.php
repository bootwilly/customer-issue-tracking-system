@if ($documents->isEmpty())
    <p class="text-sm text-slate-500">目前沒有可瀏覽的文件。</p>
@else
    <ul class="space-y-1">
        @foreach ($documents as $document)
            @php($url = route('meetings.documents.show', [$m->id, $document->id]))
            <li>
                <a class="text-indigo-700 underline" href="{{ $url }}" @click.prevent="selected = $el.href">{{ $document->original_name }}</a>
                @if ($document->owner_type === 'meeting_internal')
                    <span class="text-xs text-slate-500">僅內部</span>
                @endif
                <a class="ml-2 text-sm underline" href="{{ $url }}?download=1" aria-label="下載 {{ $document->original_name }}">下載</a>
            </li>
        @endforeach
    </ul>
@endif
