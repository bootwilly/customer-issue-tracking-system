{{-- Upload form -> preview. $flow = null for a new flow, ProjectFlow = new draft content for it. --}}
<form method="POST" action="{{ route('flows.preview') }}" enctype="multipart/form-data" class="space-y-3 rounded-lg bg-white p-4 shadow-sm">
    @csrf
    <input type="hidden" name="format" value="mermaid">
    @if ($flow)
        <input type="hidden" name="flow_id" value="{{ $flow->id }}">
    @endif
    <h2 class="text-sm font-semibold text-slate-700">{{ $flow ? '上傳新內容（存成草稿）' : '匯入流程圖（MD）' }}</h2>
    <input type="text" name="title" value="{{ old('title') }}" maxlength="200"
           placeholder="{{ $flow ? '標題（空白＝沿用草稿／目前標題）' : '標題（空白＝取 MD 第一個 # 標題，再沒有就用檔名）' }}"
           class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm">
    <input type="file" name="md_file" accept=".md,.markdown,.txt" class="block w-full text-sm">
    <details class="text-sm" @if (old('md_text')) open @endif>
        <summary class="cursor-pointer text-slate-600">或直接貼上 MD 內容</summary>
        <textarea name="md_text" rows="8" class="mt-2 w-full rounded-md border border-slate-300 px-3 py-2 font-mono text-xs"
                  placeholder="# 標題&#10;&#10;```mermaid&#10;flowchart LR&#10;  A[開始] --> B[結束]&#10;```">{{ old('md_text') }}</textarea>
    </details>
    <p class="text-xs text-slate-500">需包含至少一個 <code>```mermaid</code> 區塊，檔案為 UTF-8。下一步會先預覽並檢查語法，不會直接寫入。</p>
    <button type="submit" class="rounded-md bg-slate-800 px-4 py-2 text-sm text-white hover:bg-slate-700">預覽</button>
</form>
