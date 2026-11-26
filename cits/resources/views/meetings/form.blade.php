@extends('layouts.app')
@section('title', $meeting ? '修改會議資料' : '新增會議')
@php
    $input = 'w-full rounded-md border border-slate-300 bg-white px-3 py-1.5 text-sm focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500';
    $label = 'mb-1 block text-xs font-medium text-slate-600';
    $picked = array_map('intval', (array) old('attendee_ids', $attendeeIds));
    $isClient = ! auth()->user()->seesInternal();
@endphp
@section('content')
    <div class="mb-4 flex items-center gap-3">
        <a href="{{ $meeting ? route('meetings.show', $meeting->id) : route('meetings.index') }}" class="text-sm text-slate-500 hover:text-indigo-700">&larr; 返回</a>
        <h1 class="text-xl font-semibold">{{ $meeting ? '修改會議資料' : '新增會議' }}</h1>
    </div>

    <form method="POST" action="{{ $meeting ? route('meetings.update', $meeting->id) : route('meetings.store') }}" class="max-w-3xl space-y-4 rounded-lg bg-white p-5 shadow-sm">
        @csrf
        @if ($meeting) @method('PUT') @endif

        <div class="grid gap-4 sm:grid-cols-3">
            <div>
                <label class="{{ $label }}" for="m-type">類型</label>
                <select id="m-type" name="type" class="{{ $input }}" required>
                    @foreach ($types as $code => $name)
                        <option value="{{ $code }}" @selected(old('type', $meeting?->type) === $code)>{{ $name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="sm:col-span-2">
                <label class="{{ $label }}" for="m-title">會議主題</label>
                <input id="m-title" type="text" name="title" value="{{ old('title', $meeting?->title) }}" maxlength="200" required class="{{ $input }}">
            </div>
        </div>

        <div class="grid gap-4 sm:grid-cols-3">
            <div>
                <label class="{{ $label }}" for="m-start">開始時間</label>
                <input id="m-start" type="datetime-local" name="start_at" value="{{ old('start_at', $meeting ? tw($meeting->start_at, 'Y-m-d\TH:i') : '') }}" class="{{ $input }}">
                @unless ($meeting)<p class="mt-1 text-xs text-slate-400">「立即開始紀錄」可不填，預設現在。</p>@endunless
            </div>
            <div>
                <label class="{{ $label }}" for="m-end">結束時間（選填）</label>
                <input id="m-end" type="datetime-local" name="end_at" value="{{ old('end_at', $meeting?->end_at ? tw($meeting->end_at, 'Y-m-d\TH:i') : '') }}" class="{{ $input }}">
            </div>
            <div>
                <label class="{{ $label }}" for="m-loc">地點／會議連結（選填）</label>
                <input id="m-loc" type="text" name="location" value="{{ old('location', $meeting?->location) }}" maxlength="200" class="{{ $input }}">
            </div>
        </div>

        <fieldset>
            <legend class="{{ $label }}">與會者（專案成員）</legend>
            <div class="grid max-h-56 gap-1 overflow-y-auto rounded-md border border-slate-200 p-2 sm:grid-cols-3">
                @foreach ($members as $mem)
                    <label class="flex items-center gap-2 text-sm">
                        <input type="checkbox" name="attendee_ids[]" value="{{ $mem->id }}" @checked(in_array($mem->id, $picked, true)) class="rounded border-slate-300">
                        {{ $mem->name }}@if ($mem->isClient())<span class="rounded bg-sky-100 px-1 text-xs text-sky-800">客戶</span>@endif
                    </label>
                @endforeach
            </div>
        </fieldset>

        <div>
            <label class="{{ $label }}" for="m-ext">外部與會者（沒有帳號的人，一行一位，例如「王小明／ABC 公司」）</label>
            <textarea id="m-ext" name="external_attendees" rows="2" class="{{ $input }}">{{ old('external_attendees', $externals) }}</textarea>
        </div>

        @if ($issues->isNotEmpty())
            <div>
                <span class="{{ $label }}">從問題管理帶入的問題單（建立後成為討論事項）</span>
                <ul class="space-y-1 rounded-md border border-slate-200 p-2 text-sm">
                    @foreach ($issues as $i)
                        <li><input type="hidden" name="issue_ids[]" value="{{ $i->id }}"><span class="font-medium">{{ $i->issue_no }}</span> {{ $i->title }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        @unless ($meeting)
            <div>
                <label class="{{ $label }}" for="m-topics">預定討論的問題點（選填，一行一項）</label>
                <textarea id="m-topics" name="topics" rows="4" class="{{ $input }}">{{ old('topics') }}</textarea>
            </div>
            @if ($canShare)
                <label class="flex items-start gap-2 text-sm">
                    <input type="checkbox" name="customer_visible" value="1" @checked(old('customer_visible')) class="mt-0.5 rounded border-slate-300">
                    <span>開放給客戶<span class="block text-xs text-slate-500">專案內的客戶會先看到時間、地點、與會者與問題點；討論內容與結論要按「完成紀錄」後才看得到。內部備註永遠只有內部看得到。</span></span>
                </label>
            @elseif ($isClient)
                <p class="rounded-md bg-sky-50 px-3 py-2 text-xs text-sky-800">由客戶發起的會議，專案內所有客戶與內部人員都看得到。</p>
            @endif
        @endunless

        <div class="flex flex-wrap gap-2 border-t border-slate-100 pt-4">
            @if ($meeting)
                <button type="submit" class="rounded-md bg-indigo-600 px-4 py-1.5 text-sm font-medium text-white hover:bg-indigo-700">儲存</button>
            @else
                <button type="submit" name="mode" value="scheduled" class="rounded-md bg-indigo-600 px-4 py-1.5 text-sm font-medium text-white hover:bg-indigo-700">排定會議</button>
                <button type="submit" name="mode" value="now" class="rounded-md bg-emerald-600 px-4 py-1.5 text-sm font-medium text-white hover:bg-emerald-700">立即開始紀錄</button>
            @endif
        </div>
    </form>
@endsection
