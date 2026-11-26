{{-- Full-screen draw.io editor. $flow = null for a new flow; $doc = version the edit starts from. --}}
@php
    $base = $flow ? (int) $flow->draft_rev : null;
    $back = $flow ? route('flows.show', $flow->id) : route('flows.index');
    $src = asset('drawio/index.html').'?'.http_build_query([
        'embed' => 1, 'proto' => 'json', 'spin' => 1, 'configure' => 1,
        'saveAndExit' => 1, 'noSaveBtn' => 1, 'pages' => 0, 'lang' => 'zh-tw', 'libs' => 'general;flowchart',
    ]);
@endphp
<!DOCTYPE html>
<html lang="zh-Hant">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $flow ? '編輯：'.$flow->title : '新增流程圖' }} - 客戶問題追蹤系統</title>
    @vite(['resources/css/app.css', 'resources/js/flow/editor.js'])
    <style>
        [data-editor-status][data-tone=error] { color: #dc2626; }
        [data-editor-status][data-tone=warn] { color: #b45309; }
        [data-editor-status][data-tone=muted] { color: #64748b; }
    </style>
</head>
<body class="h-screen overflow-hidden bg-slate-100">
<div data-flow-editor class="flex h-full flex-col"
     data-draft-key="cits:flow:{{ $flow?->id ?? 'new' }}:p{{ session('project_id') }}:r{{ $base ?? 0 }}"
     data-source-url="{{ $sourceUrl }}" data-back-url="{{ $back }}">
    <form method="POST" action="{{ $flow ? route('flows.update', $flow->id) : route('flows.store') }}"
          class="flex flex-wrap items-center gap-3 border-b border-slate-200 bg-white px-4 py-2 text-sm" onsubmit="return false">
        @csrf
        @if ($flow)
            @method('PUT')
            <input type="hidden" name="base_rev" value="{{ $base }}">
        @endif
        <input type="hidden" name="format" value="drawio">
        <a href="{{ $back }}" class="text-slate-600 hover:underline">← 返回</a>
        <input type="text" name="title" value="{{ $doc['title'] ?? '' }}" maxlength="200" required placeholder="流程圖標題"
               class="w-72 rounded-md border border-slate-300 px-3 py-1.5">
        @if ($flow)
            <span class="text-xs text-slate-500">
                @if ($doc['kind'] === 'draft')編輯草稿@elseif ($doc['version'] !== $flow->version)以第 {{ $doc['version'] }} 版為底稿@else以第 {{ $flow->version }} 版為底稿@endif，儲存只會更新草稿，發布後才生效
            </span>
        @endif
        <span data-editor-status data-tone="muted" class="text-xs">編輯器載入中…</span>
    </form>
    <iframe data-src="{{ $src }}" title="draw.io 編輯器" class="w-full flex-1 border-0"></iframe>
</div>
</body>
</html>
