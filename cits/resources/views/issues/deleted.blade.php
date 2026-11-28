{{-- A deleted internal issue: in the project recycle bin, or deleted for good (from its issue_purged event). --}}
@extends('layouts.app')
@section('title', $gone['issue_no'])
@section('content')
    <div class="mb-4"><a href="{{ route('pm.index') }}" class="text-sm text-slate-500 hover:text-indigo-700">&larr; 問題管理</a></div>
    <section class="rounded-lg bg-white p-6 shadow-sm">
        <h1 class="text-lg font-semibold">{{ $gone['issue_no'] }}　{{ $gone['title'] }}</h1>
        <p class="mt-2 text-sm text-slate-600">
            @if ($gone['state'] === 'trashed')
                這張內部單已於 {{ tw($gone['at']) }} 由 {{ $gone['by'] ?? '（未知）' }} 移到回收筒。
            @else
                這張內部單已於 {{ tw($gone['at']) }} 由 {{ $gone['by'] ?? '（未知）' }} 永久刪除，無法復原。
            @endif
        </p>
        @if ($gone['state'] === 'trashed')
            @if ($canTrash)
                <a href="{{ route('padmin.trash') }}" class="mt-4 inline-block rounded-md bg-indigo-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-indigo-700">到回收筒</a>
            @else
                <p class="mt-2 text-sm text-slate-500">需要還原請聯絡專案管理員。</p>
            @endif
        @endif
    </section>
@endsection
