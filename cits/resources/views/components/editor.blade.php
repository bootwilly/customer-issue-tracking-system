@props(['name', 'value' => '', 'context' => ''])
@php
    $colors = ['#000000' => '黑', '#dc2626' => '紅', '#ea580c' => '橘', '#16a34a' => '綠', '#2563eb' => '藍', '#7c3aed' => '紫'];
    $b = 'px-2 py-1 text-sm rounded border border-gray-300 hover:bg-gray-100';
@endphp
<div data-editor data-context="{{ $context }}" class="border border-gray-300 rounded bg-white">
    <div data-editor-draftbar class="hidden px-3 py-2 text-sm bg-yellow-50 border-b border-yellow-200">
        有未存檔的草稿（<span data-draft-time></span>）
        <button type="button" data-draft-restore class="ml-2 text-blue-600 underline">還原</button>
        <button type="button" data-draft-discard class="ml-2 text-gray-600 underline">捨棄</button>
    </div>
    <div class="flex flex-wrap items-center gap-1 p-2 border-b border-gray-200 bg-gray-50">
        <button type="button" data-cmd="bold" class="{{ $b }} font-bold" title="粗體">粗體</button>
        <button type="button" data-cmd="italic" class="{{ $b }} italic" title="斜體">斜體</button>
        <select data-font-size class="px-1 py-1 text-sm rounded border border-gray-300" title="字級">
            <option value="small">小</option>
            <option value="normal" selected>正常</option>
            <option value="large">大</option>
            <option value="xlarge">特大</option>
        </select>
        <span class="flex items-center gap-1" title="文字顏色">
            @foreach ($colors as $hex => $label)
                <button type="button" data-color="{{ $hex }}" title="{{ $label }}" class="w-5 h-5 rounded-full border border-gray-300" style="background: {{ $hex }}"></button>
            @endforeach
        </span>
        <button type="button" data-cmd="bulletList" class="{{ $b }}">項目清單</button>
        <button type="button" data-cmd="orderedList" class="{{ $b }}">編號清單</button>
        <select data-table-cmd class="px-1 py-1 text-sm rounded border border-gray-300" title="表格">
            <option value="">表格…</option>
            <option value="insert">插入表格（3×3）</option>
            <option value="rowBefore" data-in-table disabled>上方插入列</option>
            <option value="rowAfter" data-in-table disabled>下方插入列</option>
            <option value="rowDelete" data-in-table disabled>刪除列</option>
            <option value="colBefore" data-in-table disabled>左側插入欄</option>
            <option value="colAfter" data-in-table disabled>右側插入欄</option>
            <option value="colDelete" data-in-table disabled>刪除欄</option>
            <option value="headerRow" data-in-table disabled>切換標題列</option>
            <option value="merge" data-in-table disabled>合併儲存格</option>
            <option value="split" data-in-table disabled>分割儲存格</option>
            <option value="tableDelete" data-in-table disabled>刪除表格</option>
        </select>
        <button type="button" data-cmd="image" class="{{ $b }}">插入圖片</button>
        <button type="button" data-cmd="drawing" class="hidden md:inline-block {{ $b }}" title="勾選內文中的圖片，在圖上畫框、圓、箭頭（可多張一起）；雙擊繪圖可再編輯">繪圖</button>
        <button type="button" data-cmd="attach" class="{{ $b }}">附加檔案</button>
        <button type="button" data-cmd="undo" class="{{ $b }}">復原</button>
        <button type="button" data-cmd="redo" class="{{ $b }}">重做</button>
        <span data-editor-status class="ml-2 text-xs text-gray-500"></span>
    </div>
    <div data-editor-area></div>
    <input type="file" accept="image/*" multiple class="hidden" data-image-file>
    <input type="file" multiple class="hidden" data-attach-file>
    <input type="hidden" name="{{ $name }}" value="{{ $value }}" data-editor-input>
</div>
