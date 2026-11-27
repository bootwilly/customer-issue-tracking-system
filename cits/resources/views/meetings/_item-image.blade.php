<figure data-image-id="{{ $p['id'] }}" class="w-28">
    <img src="{{ $p['url'] }}" alt="附圖" loading="lazy" data-image-view class="h-20 w-28 cursor-zoom-in rounded border border-slate-200 bg-slate-50 object-contain">
    <figcaption class="mt-0.5 flex justify-between text-xs">
        <button type="button" data-image-draw class="text-indigo-700 hover:underline">標記</button>
        <button type="button" data-image-remove class="text-red-600 hover:underline">移除</button>
    </figcaption>
</figure>
