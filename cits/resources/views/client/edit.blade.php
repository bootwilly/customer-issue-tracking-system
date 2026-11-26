@extends('layouts.app')
@section('title', '編輯 '.$ticket->ticket_no)
@push('head')
    @vite('resources/js/editor/index.js')
@endpush
@section('content')
    <h1 class="mb-4 text-xl font-semibold">編輯 {{ $ticket->ticket_no }}</h1>
    @if ($isRevision)
        <div class="mb-4 rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">PM 已接收，存檔會產生新的修訂版（rev {{ $ticket->current_rev + 1 }}），立即生效並通知 PM</div>
    @endif
    <div x-data="{ type: @js(old('ticket_type', $ticket->ticket_type)), anomalyTypes: @js($anomalyTypes), lost: false }"
         x-init="setInterval(async () => {
            try {
                const r = await fetch('{{ route('tickets.heartbeat', $ticket->id) }}', { method: 'POST', headers: { 'X-CSRF-TOKEN': '{{ csrf_token() }}', 'Accept': 'application/json', 'X-CITS-Background': '1' } });
                const j = await r.json(); if (!j.ok) lost = true;
            } catch (e) {}
         }, 60000)">
        <div x-show="lost" x-cloak class="mb-4 rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">編輯鎖已失效，請取消後重新進入編輯。</div>
        <form id="edit-form" method="POST" action="{{ route('tickets.update', $ticket->id) }}" class="rounded-lg bg-white p-4 shadow-sm">
            @csrf @method('PUT')
            <input type="hidden" name="row_version" value="{{ old('row_version', $ticket->row_version) }}">
            @include('client._form', ['context' => 'ticket:edit:'.$ticket->id])
            <div class="mt-4 flex gap-2">
                <button class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700">儲存</button>
                <button type="submit" form="release-form" data-editor-cancel class="rounded-md border border-slate-300 px-4 py-2 text-sm text-slate-700 hover:bg-slate-50">取消</button>
            </div>
        </form>
        <form id="release-form" method="POST" action="{{ route('tickets.release', $ticket->id) }}">@csrf</form>
    </div>
@endsection
@push('scripts')
    <script>
        (function () {
            let saving = false;
            document.getElementById('edit-form').addEventListener('submit', () => { saving = true; });
            document.getElementById('release-form').addEventListener('submit', () => { saving = true; });
            window.addEventListener('pagehide', () => {
                if (saving) return;
                const fd = new FormData();
                fd.append('_token', '{{ csrf_token() }}');
                fd.append('beacon', '1');
                navigator.sendBeacon('{{ route('tickets.release', $ticket->id) }}', fd);
            });
        })();
    </script>
@endpush
