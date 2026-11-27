@extends('layouts.app')
@section('title', '討論事項轉內部單')
@push('head')
    @vite('resources/js/editor/index.js')
@endpush
@php
    $input = 'w-full rounded-md border border-slate-300 bg-white px-2 py-1.5 text-sm focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500';
    $back = route('meetings.show', $meeting->id).'#item-'.$item->id;
@endphp
@section('content')
    <a href="{{ $back }}" class="text-sm text-slate-500 hover:text-indigo-700">&larr; {{ $meeting->title }}</a>
    <h1 class="mb-1 text-xl font-semibold">討論事項轉內部單</h1>
    <p class="mb-4 text-sm text-slate-500">第 {{ $no }} 項「{{ $item->topic }}」：建立後這個事項會連結到新的內部單（客戶看不到單號）。</p>
    <form method="POST" action="{{ route('meetings.items.issue.store', [$meeting->id, $item->id]) }}" class="max-w-3xl space-y-4 rounded-lg bg-white p-4 shadow-sm">
        @csrf
        <div><label class="text-sm font-medium">標題</label><input name="title" required maxlength="200" value="{{ old('title', $title) }}" class="{{ $input }}"></div>
        <div class="grid gap-3 md:grid-cols-4">
            <div><label class="text-sm font-medium">來源</label>
                <select name="origin" required class="{{ $input }}">
                    @foreach ($origins as $code => $label)<option value="{{ $code }}" @selected(old('origin') === $code)>{{ $label }}</option>@endforeach
                </select></div>
            <div><label class="text-sm font-medium">處理類型</label>
                <select name="process_type" class="{{ $input }}"><option value="">-</option>
                    @foreach ($processTypes as $p)<option value="{{ $p->code }}" @selected(old('process_type') === $p->code)>{{ $p->name }}</option>@endforeach
                </select></div>
            <div><label class="text-sm font-medium">MES 頁面</label>
                <select name="mes_page_id" class="{{ $input }}"><option value="">-</option>
                    @foreach ($mesPages as $mp)<option value="{{ $mp->id }}" @selected((int) old('mes_page_id') === $mp->id)>{{ $mp->name }}</option>@endforeach
                </select></div>
            <div><label class="text-sm font-medium">嚴重度（1-5）</label>
                <select name="severity" required class="{{ $input }}"><option value="">請選擇</option>
                    @for ($k = 1; $k <= 5; $k++)<option value="{{ $k }}" @selected((int) old('severity') === $k)>{{ $k }}</option>@endfor
                </select></div>
        </div>
        <div><label class="text-sm font-medium">內容</label>
            <x-editor name="content_html" :value="old('content_html', $content_html)" :context="$context" /></div>
        <div class="flex gap-3">
            <button class="rounded-md bg-indigo-600 px-4 py-1.5 text-sm font-medium text-white hover:bg-indigo-700">建立內部單</button>
            <a href="{{ $back }}" data-editor-cancel class="rounded-md bg-slate-100 px-4 py-1.5 text-sm hover:bg-slate-200">取消</a>
        </div>
    </form>
@endsection
