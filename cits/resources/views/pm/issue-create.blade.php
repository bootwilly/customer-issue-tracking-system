@extends('layouts.app')
@section('title', '新增內部問題')
@push('head')
    @vite('resources/js/editor/index.js')
@endpush
@php
    $input = 'w-full rounded-md border border-slate-300 bg-white px-2 py-1.5 text-sm focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500';
@endphp
@section('content')
    <h1 class="mb-4 text-xl font-semibold">新增內部問題</h1>
    <form method="POST" action="{{ route('pm.issues.store') }}" class="max-w-3xl space-y-4 rounded-lg bg-white p-4 shadow-sm">
        @csrf
        <input type="hidden" name="draft" value="issue:new:{{ $pid }}">
        <div><label class="text-sm font-medium">標題</label><input name="title" required maxlength="200" value="{{ old('title') }}" class="{{ $input }}">@include('collab._similar', ['url' => route('collab.similar.internal')])</div>
        <div class="grid gap-3 md:grid-cols-3">
            <div><label class="text-sm font-medium">處理類型</label>
                <select name="process_type" class="{{ $input }}"><option value="">-</option>
                    @foreach ($processTypes as $p)<option value="{{ $p->code }}" @selected(old('process_type') === $p->code)>{{ $p->name }}</option>@endforeach
                </select></div>
            <div><label class="text-sm font-medium">MES 頁面</label>
                <select name="mes_page_id" class="{{ $input }}"><option value="">-</option>
                    @foreach ($mesPages as $m)<option value="{{ $m->id }}" @selected((int) old('mes_page_id') === $m->id)>{{ $m->name }}</option>@endforeach
                </select></div>
            <div><label class="text-sm font-medium">嚴重度（1-5）</label>
                <select name="severity" required class="{{ $input }}"><option value="">請選擇</option>
                    @for ($k = 1; $k <= 5; $k++)<option value="{{ $k }}" @selected((int) old('severity') === $k)>{{ $k }}</option>@endfor
                </select></div>
        </div>
        <div><label class="text-sm font-medium">內容</label>
            <x-editor name="content_html" :value="old('content_html', '')" context="issue:new:{{ $pid }}" /></div>
        <div class="flex gap-3">
            <button class="rounded-md bg-indigo-600 px-4 py-1.5 text-sm font-medium text-white hover:bg-indigo-700">建立</button>
            <a href="{{ route('pm.index') }}" data-editor-cancel class="rounded-md bg-slate-100 px-4 py-1.5 text-sm hover:bg-slate-200">取消</a>
        </div>
    </form>
@endsection
