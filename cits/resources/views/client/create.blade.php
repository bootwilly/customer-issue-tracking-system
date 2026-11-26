@extends('layouts.app')
@section('title', '開立新單')
@push('head')
    @vite('resources/js/editor/index.js')
@endpush
@section('content')
    <h1 class="mb-4 text-xl font-semibold">開立新單</h1>
    <form method="POST" action="{{ route('tickets.store') }}" class="rounded-lg bg-white p-4 shadow-sm"
          x-data="{ type: @js(old('ticket_type', '')), anomalyTypes: @js($anomalyTypes) }">
        @csrf
        @include('client._form', ['context' => 'ticket:new:'.$pid])
        <div class="mt-4 flex gap-2">
            <button class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700">送出</button>
            <a href="{{ route('tickets.index') }}" data-editor-cancel class="rounded-md border border-slate-300 px-4 py-2 text-sm text-slate-700 hover:bg-slate-50">取消</a>
        </div>
    </form>
@endsection
