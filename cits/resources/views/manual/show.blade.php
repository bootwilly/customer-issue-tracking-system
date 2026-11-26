@extends('layouts.app')
@section('title', '操作手冊 - '.$chapters[$chapter])
@push('head')
    @vite('resources/js/flow/index.js')
@endpush
@section('content')
    {{-- plain CSS: no front-end rebuild needed for this page --}}
    <style>
        .manual { display: flex; flex-wrap: wrap; align-items: flex-start; gap: 1rem; }
        .manual-toc { flex: 0 0 15rem; position: sticky; top: 1rem; }
        .manual-toc a { display: block; padding: .35rem .6rem; border-radius: .375rem; font-size: .875rem; color: #475569; }
        .manual-toc a:hover { background: #f1f5f9; }
        .manual-toc a[aria-current] { background: #eef2ff; color: #4338ca; font-weight: 500; }
        .manual-body { flex: 1 1 0; min-width: 0; }
        .manual-body .flow-doc img { display: block; max-width: 100%; margin: .75rem auto; border: 1px solid #cbd5e1; border-radius: .375rem; }
        .manual-body .flow-doc table { display: block; overflow-x: auto; }
        @media (max-width: 800px) { .manual-toc { position: static; flex-basis: 100%; } .manual-body { flex-basis: 100%; } }
        @media print { header, .manual-toc { display: none; } }
    </style>
    <div class="manual">
        <nav class="manual-toc rounded-lg bg-white p-2 shadow-sm">
            @foreach ($chapters as $code => $title)
                <a href="{{ route('manual.show', $code === 'README' ? null : $code) }}" @if ($code === $chapter) aria-current="page" @endif>{{ $code === 'README' ? '首頁' : $title }}</a>
            @endforeach
        </nav>
        <div class="manual-body">
            @include('flows._doc', ['html' => $html])
        </div>
    </div>
@endsection
