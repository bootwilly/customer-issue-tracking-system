@php
    $curType = old('ticket_type', $ticket?->ticket_type ?? '');
@endphp
<div class="grid gap-4 md:grid-cols-2">
    <label class="block text-sm font-medium text-slate-700">類型
        <select name="ticket_type" x-model="type" required class="mt-1 block w-full rounded-md border border-slate-300 py-2 text-sm">
            <option value="">請選擇</option>
            @foreach ($types as $code => $name)
                <option value="{{ $code }}">{{ $name }}</option>
            @endforeach
        </select>
    </label>
    <label class="block text-sm font-medium text-slate-700" x-show="anomalyTypes.includes(type)" x-cloak>異常類別
        <select name="anomaly_category" class="mt-1 block w-full rounded-md border border-slate-300 py-2 text-sm">
            <option value="">請選擇</option>
            @foreach ($categories as $code => $name)
                <option value="{{ $code }}" @selected(old('anomaly_category', $ticket?->anomaly_category) === $code)>{{ $name }}</option>
            @endforeach
        </select>
    </label>
    <label class="block text-sm font-medium text-slate-700 md:col-span-2">功能頁面
        <select name="mes_page_id" class="mt-1 block w-full rounded-md border border-slate-300 py-2 text-sm">
            <option value="">（不指定）</option>
            @foreach ($pages as $id => $label)
                <option value="{{ $id }}" @selected((string) old('mes_page_id', $ticket?->mes_page_id) === (string) $id)>{{ $label }}</option>
            @endforeach
        </select>
    </label>
    <label class="block text-sm font-medium text-slate-700 md:col-span-2">標題
        <input type="text" name="title" maxlength="200" required value="{{ old('title', $ticket?->title) }}" class="mt-1 block w-full rounded-md border border-slate-300 py-2 text-sm">
        @include('collab._similar', ['url' => route('collab.similar.tickets'), 'except' => $ticket?->id])
    </label>
    <div class="md:col-span-2">
        <div class="mb-1 text-sm font-medium text-slate-700">內容</div>
        <x-editor name="content_html" :value="old('content_html', $ticket?->content_html ?? '')" :context="$context" />
    </div>
</div>
