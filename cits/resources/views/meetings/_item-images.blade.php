{{-- Pictures of one discussion item. $editable: thumbnails with 標記 / 移除 and an add button (bound by resources/js/meeting/items.js). --}}
@php
    $pics = \App\Http\Controllers\Meeting\MeetingController::imageJson($list);
@endphp
@if ($editable)
    <div data-item-images class="mt-2 flex flex-wrap items-start gap-2 pl-8">
        <div data-image-list class="contents">
            @foreach ($pics as $p)
                @include('meetings._item-image', ['p' => $p])
            @endforeach
        </div>
        <label class="flex h-20 w-20 cursor-pointer flex-col items-center justify-center rounded border border-dashed border-slate-300 text-xs text-slate-500 hover:bg-slate-50" title="也可以直接在文字框貼上截圖，或把圖片拖進來">
            ＋ 圖片<input type="file" accept="image/*" multiple data-image-input class="hidden">
        </label>
    </div>
@elseif ($pics)
    <div class="mt-2 flex flex-wrap gap-2 pl-8">
        @foreach ($pics as $p)
            <a href="{{ $p['url'] }}" target="_blank" rel="noopener"><img src="{{ $p['url'] }}" alt="附圖" loading="lazy" class="h-20 max-w-40 rounded border border-slate-200 object-contain"></a>
        @endforeach
    </div>
@endif
