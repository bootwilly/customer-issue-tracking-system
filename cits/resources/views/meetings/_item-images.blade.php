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
        <div class="flex flex-col gap-1">
            <button type="button" data-image-paste class="rounded-md bg-indigo-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-indigo-700">📋 貼上截圖</button>
            <label class="cursor-pointer text-center text-xs text-slate-500 hover:text-indigo-700 hover:underline">
                或選擇圖片檔<input type="file" accept="image/*" multiple data-image-input class="hidden">
            </label>
        </div>
    </div>
@elseif ($pics)
    <div class="mt-2 flex flex-wrap gap-2 pl-8">
        @foreach ($pics as $p)
            <a href="{{ $p['url'] }}" target="_blank" rel="noopener"><img src="{{ $p['url'] }}" alt="附圖" loading="lazy" class="h-20 max-w-40 rounded border border-slate-200 object-contain"></a>
        @endforeach
    </div>
@endif
