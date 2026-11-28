@extends('layouts.app')
@section('title', 'Excel 匯入內部問題')
@push('head')
    @vite('resources/js/pm/import.js')
@endpush
@php
    $input = 'rounded-md border border-slate-300 bg-white px-2 py-1.5 text-sm focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500';
@endphp
@section('content')
    <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
        <h1 class="text-xl font-semibold">Excel 匯入內部問題</h1>
        <div class="flex gap-2">
            <a href="{{ route('pm.issues.import.template') }}" class="rounded-md border border-emerald-600 px-3 py-1.5 text-sm font-medium text-emerald-700 hover:bg-emerald-50">下載標準範本</a>
            <a href="{{ route('pm.index') }}" class="rounded-md bg-slate-100 px-3 py-1.5 text-sm hover:bg-slate-200">回 PM 工作台</a>
        </div>
    </div>

    <div id="issue-import" class="space-y-4"
        data-preview-url="{{ route('pm.issues.import.preview') }}"
        data-apply-url="{{ route('pm.issues.import.apply') }}"
        data-media-url="{{ route('pm.issues.import.media', ['file' => '__FILE__']) }}"
        data-upload-base="{{ url('/uploads') }}"
        data-file-context="{{ $fileContext }}">

        <section class="rounded-lg bg-white p-4 shadow-sm">
            <p class="mb-3 text-sm text-slate-600">只接受系統的標準範本（.xlsx）。上傳後先預覽，不會寫入任何資料；確認後才一次建立。已匯入過的列（同一個來源代碼 + 序號）不會重複建立。</p>
            <div class="grid gap-3 md:grid-cols-[16rem_1fr_auto] md:items-end">
                <div>
                    <label class="text-sm font-medium">來源代碼</label>
                    <input data-source list="import-sources" maxlength="50" required class="{{ $input }} w-full" placeholder="例如 YC-MES-MEETING" value="{{ $sources[0] ?? '' }}">
                    <datalist id="import-sources">@foreach ($sources as $s)<option value="{{ $s }}">@endforeach</datalist>
                </div>
                <div>
                    <label class="text-sm font-medium">Excel 檔</label>
                    <input data-file type="file" accept=".xlsx" class="{{ $input }} w-full">
                </div>
                <button data-preview type="button" class="rounded-md bg-indigo-600 px-4 py-1.5 text-sm font-medium text-white hover:bg-indigo-700 disabled:opacity-50">上傳並預覽</button>
            </div>
            <p class="mt-2 text-xs text-slate-500">同一份持續更新的紀錄請一直用同一個來源代碼；換了代碼，系統就認不出哪些列已經匯入過。</p>
            <p data-status class="mt-2 text-sm text-slate-700" role="status"></p>
        </section>

        <section data-result class="hidden rounded-lg bg-emerald-50 p-4 text-sm shadow-sm"></section>

        <section data-preview-area class="hidden rounded-lg bg-white p-4 shadow-sm">
            <div class="mb-3 flex flex-wrap items-center justify-between gap-3">
                <div data-counts class="flex flex-wrap gap-2 text-sm"></div>
                <div class="flex items-center gap-3">
                    <label class="text-sm">本批新單出處
                        <select data-provenance class="{{ $input }}">
                            <option value="">請選擇</option>
                            @foreach ($provenances as $code => $label)<option value="{{ $code }}">{{ $label }}</option>@endforeach
                        </select>
                    </label>
                    <label class="text-sm"><input data-toggle-all type="checkbox" checked> 全選新建列</label>
                    <button data-apply type="button" class="rounded-md bg-emerald-600 px-4 py-1.5 text-sm font-medium text-white hover:bg-emerald-700 disabled:opacity-50">確認匯入</button>
                </div>
            </div>
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead class="bg-slate-50 text-left text-xs text-slate-600">
                        <tr>
                            <th class="px-2 py-2"></th>
                            <th class="px-2 py-2">序號</th>
                            <th class="px-2 py-2">結果</th>
                            <th class="px-2 py-2">標題</th>
                            <th class="px-2 py-2">處理類型</th>
                            <th class="px-2 py-2">MES 頁面</th>
                            <th class="px-2 py-2">嚴重度</th>
                            <th class="px-2 py-2">圖片</th>
                            <th class="px-2 py-2">提示</th>
                            <th class="px-2 py-2"></th>
                        </tr>
                    </thead>
                    <tbody data-rows></tbody>
                </table>
            </div>
        </section>
    </div>
@endsection
