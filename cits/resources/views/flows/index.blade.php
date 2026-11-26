@extends('layouts.app')
@section('title', '流程圖')
@section('content')
    <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
        <h1 class="text-xl font-semibold">專案流程圖</h1>
        <div class="flex flex-wrap items-center gap-2">
            <form method="GET" action="{{ route('flows.index') }}" class="flex gap-1">
                <input type="hidden" name="status" value="{{ $status ?? '' }}">
                <input type="search" name="q" value="{{ $q }}" maxlength="100" placeholder="搜尋標題或圖中文字"
                       class="w-56 rounded-md border border-slate-300 px-3 py-1.5 text-sm">
                <button type="submit" class="rounded-md bg-slate-100 px-3 text-sm hover:bg-slate-200">搜尋</button>
            </form>
            @if ($canManage)
                @foreach ($formats as $code => $name)
                    @if ($code === 'drawio')
                        <a href="{{ route('flows.create') }}" class="hidden rounded-md bg-indigo-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-indigo-700 md:inline-block">＋ {{ $name }}</a>
                    @elseif ($code === 'mermaid')
                        <a href="#flow-upload" class="rounded-md bg-white px-3 py-1.5 text-sm text-slate-700 ring-1 ring-slate-300 hover:bg-slate-50">＋ {{ $name }}</a>
                    @endif
                @endforeach
            @endif
        </div>
    </div>

    <nav class="mb-3 flex flex-wrap gap-1 text-sm">
        @foreach (['' => '全部'] + $statusFilters as $code => $label)
            @php $on = (string) ($status ?? '') === (string) $code; @endphp
            <a href="{{ route('flows.index', array_filter(['status' => $code === '' ? '' : $code, 'q' => $q], fn ($v) => $v !== null) ) }}"
               class="rounded-full px-3 py-1 {{ $on ? 'bg-slate-800 text-white' : 'bg-white text-slate-600 ring-1 ring-slate-200 hover:bg-slate-50' }}">{{ $label }}</a>
        @endforeach
    </nav>

    <div class="grid gap-4 {{ $canManage ? 'lg:grid-cols-3' : '' }}">
        <div class="{{ $canManage ? 'lg:col-span-2' : '' }} overflow-x-auto rounded-lg bg-white shadow-sm">
            <table class="min-w-full divide-y divide-slate-200 text-sm">
                <thead class="bg-slate-50 text-left text-xs text-slate-500">
                    <tr>
                        <th class="px-3 py-2">標題</th>
                        <th class="px-3 py-2">狀態</th>
                        <th class="px-3 py-2">格式</th>
                        <th class="px-3 py-2">生效版</th>
                        <th class="px-3 py-2">最後更新</th>
                        @if ($canManage)
                            <th class="px-3 py-2">排序</th>
                        @endif
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse ($flows as $f)
                        <tr>
                            <td class="px-3 py-2">
                                <a href="{{ route('flows.show', $f->id) }}" class="font-medium text-indigo-700 hover:underline">{{ $f->title }}</a>
                                @if ($f->has_draft && $f->isPublished())
                                    <span class="ml-1 rounded bg-amber-100 px-1.5 py-0.5 text-xs text-amber-800" title="{{ $f->draftEditor?->name }} {{ $f->draft_updated_at?->timezone('Asia/Taipei')->format('m-d H:i') }}">草稿修改中</span>
                                @endif
                            </td>
                            <td class="px-3 py-2"><x-badge scope="flow" :status="$f->status" /></td>
                            <td class="px-3 py-2 text-xs text-slate-500">{{ $formats[$f->format] ?? $f->format }}</td>
                            <td class="px-3 py-2">{{ $f->isPublished() ? 'v'.$f->version : '—' }}</td>
                            <td class="px-3 py-2 text-xs text-slate-500">{{ $f->updated_at?->timezone('Asia/Taipei')->format('Y-m-d H:i') }}　{{ $f->editor?->name }}</td>
                            @if ($canManage)
                                <td class="px-3 py-2">
                                    <form method="POST" action="{{ route('flows.sort', $f->id) }}" class="flex gap-1">
                                        @csrf
                                        <input type="number" name="sort" value="{{ $f->sort }}" min="0" max="60000" class="w-20 rounded border border-slate-300 px-2 py-1 text-xs">
                                        <button type="submit" class="rounded bg-slate-100 px-2 text-xs hover:bg-slate-200">存</button>
                                    </form>
                                </td>
                            @endif
                        </tr>
                    @empty
                        <tr><td colspan="6" class="px-3 py-6 text-center text-slate-500">{{ $q !== '' ? '找不到符合「'.$q.'」的流程圖。' : '目前沒有流程圖。' }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($canManage && array_key_exists('mermaid', $formats))
            <div id="flow-upload">@include('flows._upload', ['flow' => null])</div>
        @endif
    </div>
@endsection
