@extends('layouts.app')
@section('title', '流程圖預覽')
@push('head')
    @vite('resources/js/flow/index.js')
@endpush
@section('content')
    <div class="mb-4 flex items-center justify-between">
        <h1 class="text-xl font-semibold">{{ $flow ? '新版本預覽：'.$flow->title : '新流程圖預覽' }}</h1>
        <a href="{{ $flow ? route('flows.show', $flow->id) : route('flows.index') }}" class="text-sm text-slate-600 hover:underline">取消</a>
    </div>

    <div class="mb-4 space-y-2 rounded-lg bg-white p-4 text-sm shadow-sm">
        <p>標題：<strong>{{ $parsed['title'] }}</strong>　流程圖區塊：{{ $parsed['blocks'] }} 個　大小：{{ number_format(strlen($parsed['md'])) }} bytes @if ($fileName)　檔案：{{ $fileName }}@endif</p>
        @if ($flow)
            <p class="text-slate-600">儲存後會更新這份流程圖的草稿，不會改變目前生效的版本；要按「發布」才會生效。</p>
        @endif
        <p data-flow-status class="text-slate-500">語法檢查中…</p>
        @if ($unchanged)
            <p class="text-amber-700">內容與目前草稿（或生效版）相同，不需要儲存。</p>
        @else
            <form method="POST" action="{{ $flow ? route('flows.update', $flow->id) : route('flows.store') }}">
                @csrf
                @if ($flow)
                    @method('PUT')
                    <input type="hidden" name="base_rev" value="{{ $flow->draft_rev }}">
                @endif
                <input type="hidden" name="title" value="{{ $parsed['title'] }}">
                <input type="hidden" name="format" value="mermaid">
                <input type="hidden" name="file_name" value="{{ $fileName }}">
                <textarea name="source_md" class="hidden">
{{ $parsed['md'] }}</textarea>
                <button type="submit" data-flow-submit disabled class="rounded-md bg-indigo-600 px-4 py-2 font-medium text-white hover:bg-indigo-700 disabled:cursor-not-allowed disabled:opacity-50">儲存為草稿</button>
            </form>
        @endif
    </div>

    @include('flows._doc', ['html' => $html])
@endsection
