@extends('layouts.app')
@section('title', '新增開發問題')
@push('head')
    @vite('resources/js/editor/index.js')
@endpush
@section('content')
    <div class="mb-4 flex items-center justify-between">
        <h1 class="text-xl font-semibold">新增開發問題</h1>
        <a href="{{ route('dev.index') }}" class="text-sm text-slate-600 hover:underline">返回列表</a>
    </div>
    <form method="POST" action="{{ route('dev.issues.store') }}" class="space-y-4 rounded-lg bg-white p-4 shadow-sm">
        @csrf
        <div>
            <label class="mb-1 block text-sm font-medium">標題</label>
            <input type="text" name="title" value="{{ old('title') }}" required maxlength="200" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm">
            @include('collab._similar', ['url' => route('collab.similar.internal')])
        </div>
        <div class="grid gap-4 md:grid-cols-3">
            <div>
                <label class="mb-1 block text-sm font-medium">處理類型</label>
                <select name="process_type" class="w-full rounded-md border border-slate-300 px-2 py-2 text-sm">
                    <option value="">（未指定）</option>
                    @foreach ($processTypes as $code => $name)
                        <option value="{{ $code }}" @selected(old('process_type') === $code)>{{ $name }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="mb-1 block text-sm font-medium">MES 頁面</label>
                <select name="mes_page_id" class="w-full rounded-md border border-slate-300 px-2 py-2 text-sm">
                    <option value="">（未指定）</option>
                    @foreach ($mesPages as $p)
                        <option value="{{ $p->id }}" @selected((string) old('mes_page_id') === (string) $p->id)>{{ $p->name }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="mb-1 block text-sm font-medium">嚴重程度</label>
                <select name="severity" class="w-full rounded-md border border-slate-300 px-2 py-2 text-sm">
                    <option value="">（未指定）</option>
                    @for ($s = 1; $s <= 5; $s++)
                        <option value="{{ $s }}" @selected((string) old('severity') === (string) $s)>{{ $s }}</option>
                    @endfor
                </select>
            </div>
        </div>
        <div>
            <label class="mb-1 block text-sm font-medium">內容</label>
            <x-editor name="content_html" :value="old('content_html', '')" :context="'issue:new:'.$pid" />
        </div>
        <button type="submit" class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700">建立</button>
    </form>
@endsection
