@extends('layouts.app')
@section('title', '版本比較')
@section('content')
    <div class="mb-4 flex items-center justify-between">
        <h1 class="text-xl font-semibold">版本比較　{{ $ticket->ticket_no }}　第 {{ $new->rev_no }} 版 vs {{ $old ? '第 '.$old->rev_no.' 版' : '（無前一版）' }}</h1>
        <a href="{{ $isClient ? route('tickets.show', $ticket->id) : route('pm.tickets.show', $ticket->id) }}" class="text-sm text-slate-600 hover:underline">返回客戶單</a>
    </div>

    <section class="mb-4 rounded-lg bg-white p-4 shadow-sm">
        <h2 class="mb-2 text-sm font-semibold text-slate-700">標題</h2>
        @if ($title['changed'])
            @if ($old)
                <div class="rounded bg-red-100 px-2 py-1 text-sm text-red-800">- {{ $title['old'] }}</div>
            @endif
            <div class="mt-1 rounded bg-emerald-100 px-2 py-1 text-sm text-emerald-800">+ {{ $title['new'] }}</div>
        @else
            <div class="px-2 py-1 text-sm text-slate-700">{{ $title['new'] }}</div>
        @endif
        <p class="mt-3 text-xs text-slate-500">客戶單屬性（目前值；版本紀錄不保存這些欄位的歷史）：
            @foreach ($meta as $k => $v)
                <span class="mr-3">{{ $k }}：{{ $v }}</span>
            @endforeach
        </p>
    </section>

    <section class="rounded-lg bg-white p-4 shadow-sm">
        <h2 class="mb-2 text-sm font-semibold text-slate-700">內容</h2>
        @if (! $lines)
            <x-empty>沒有內容。</x-empty>
        @else
            <div class="overflow-x-auto font-mono text-sm">
                @foreach ($lines as $l)
                    @php
                        $cls = ['same' => 'text-slate-700', 'add' => 'bg-emerald-100 text-emerald-800', 'del' => 'bg-red-100 text-red-800'][$l['type']];
                        $mark = ['same' => ' ', 'add' => '+', 'del' => '-'][$l['type']];
                    @endphp
                    <div class="whitespace-pre-wrap break-words px-2 py-0.5 {{ $cls }}">{{ $mark }} {{ $l['text'] }}</div>
                @endforeach
            </div>
        @endif
    </section>
@endsection
