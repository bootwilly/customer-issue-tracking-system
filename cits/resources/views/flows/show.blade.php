@extends('layouts.app')
@section('title', $doc['title'])
@push('head')
    @vite($doc['format'] === 'drawio' ? 'resources/js/flow/view.js' : 'resources/js/flow/index.js')
@endpush
@php
    $isDraft = $doc['kind'] === 'draft';
    $vParam = $isDraft ? 'draft' : $doc['version'];
    $btn = 'rounded-md px-3 py-1.5 text-sm';
@endphp
@section('content')
    <div class="mb-4 flex flex-wrap items-center justify-between gap-2">
        <div>
            <h1 class="text-xl font-semibold">{{ $doc['title'] }}
                <x-badge scope="flow" :status="$flow->status" class="ml-1 align-middle" />
                <span class="ml-1 rounded-full bg-indigo-50 px-2 py-0.5 align-middle text-xs font-normal text-indigo-700">{{ $formatLabel }}</span>
            </h1>
            <p class="text-xs text-slate-500">
                @if ($isDraft)
                    草稿（第 {{ $flow->draft_rev }} 次儲存）　{{ $doc['at']?->timezone('Asia/Taipei')->format('Y-m-d H:i') }}　{{ $doc['author'] }}
                @else
                    第 {{ $doc['version'] }} 版　{{ $doc['at']?->timezone('Asia/Taipei')->format('Y-m-d H:i') }}　{{ $doc['author'] }}
                    @if ($doc['note'])　變更說明：{{ $doc['note'] }}@endif
                @endif
            </p>
        </div>
        <div class="flex flex-wrap items-center gap-3 text-sm">
            @if ($canEditDraft && $doc['format'] === 'drawio')
                <a href="{{ route('flows.edit', ($isDraft || $doc['is_latest']) ? $flow->id : ['id' => $flow->id, 'v' => $doc['version']]) }}"
                   class="hidden {{ $btn }} bg-indigo-600 font-medium text-white hover:bg-indigo-700 md:inline-block">
                    {{ $isDraft || $doc['is_latest'] ? ($flow->hasDraft() ? '繼續編輯草稿' : '線上編輯') : '以此版為底稿編輯' }}
                </a>
            @endif
            <a href="{{ route('flows.download', ['id' => $flow->id, 'v' => $vParam]) }}" class="text-slate-600 hover:underline">下載 {{ $doc['format'] === 'drawio' ? '.drawio.svg' : '.md' }}</a>
            <a href="{{ route('flows.index') }}" class="text-slate-600 hover:underline">返回列表</a>
        </div>
    </div>

    @if (! $isDraft && $isTerminal)
        <div class="mb-4 rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm font-medium text-red-700">此流程圖已「{{ $statusLabel }}」，僅供查閱，請勿依此作業。</div>
    @endif
    @if ($isDraft)
        <div class="mb-4 rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
            你正在看<strong>草稿</strong>（一般成員看不到）。
            @if ($flow->isPublished())<a href="{{ route('flows.show', $flow->id) }}" class="underline">看目前生效版（第 {{ $flow->version }} 版）</a>@endif
        </div>
    @elseif (! $doc['is_latest'])
        <div class="mb-4 rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
            你正在看舊版本（第 {{ $doc['version'] }} 版），<a href="{{ route('flows.show', $flow->id) }}" class="underline">回最新版（第 {{ $flow->version }} 版）</a>
        </div>
    @elseif ($canSeeDraft && $flow->hasDraft())
        <div class="mb-4 rounded-md border border-sky-200 bg-sky-50 px-4 py-3 text-sm text-sky-800">
            有未發布的草稿（{{ $flow->draftEditor?->name }}，{{ $flow->draft_updated_at?->timezone('Asia/Taipei')->format('m-d H:i') }}）。
            <a href="{{ route('flows.show', ['id' => $flow->id, 'v' => 'draft']) }}" class="font-medium underline">檢視草稿</a>
        </div>
    @endif

    <div class="grid gap-4 lg:grid-cols-4">
        <div class="lg:col-span-3">
            @if ($doc['format'] === 'drawio')
                <div data-flow-zoom class="h-[75vh] rounded-lg border border-slate-200 shadow-sm">
                    <img src="{{ route('flows.image', ['id' => $flow->id, 'v' => $vParam]) }}" alt="{{ $doc['title'] }}">
                </div>
            @else
                @include('flows._doc', ['html' => $html])
            @endif
        </div>

        <aside class="space-y-4">
            @if ($canManage && $flow->hasDraft() && $isDraft)
                <form method="POST" action="{{ route('flows.publish', $flow->id) }}" class="space-y-2 rounded-lg border border-indigo-200 bg-white p-4 shadow-sm">
                    @csrf
                    <h2 class="text-sm font-semibold text-slate-700">發布這份草稿</h2>
                    <p class="text-xs text-slate-500">發布後成為第 {{ $flow->version + 1 }} 版並立即生效，所有成員都看得到。</p>
                    <textarea name="note" rows="3" maxlength="500" required placeholder="變更說明（必填）"
                              class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm">{{ old('note') }}</textarea>
                    <button type="submit" class="{{ $btn }} w-full bg-indigo-600 font-medium text-white hover:bg-indigo-700">發布第 {{ $flow->version + 1 }} 版</button>
                </form>
            @endif

            @if ($canEditDraft && $flow->hasDraft() && $flow->isPublished())
                <form method="POST" action="{{ route('flows.discard', $flow->id) }}" onsubmit="return confirm(@js('確定捨棄草稿？草稿與所有儲存紀錄都會清掉，回到第 '.$flow->version.' 版。'))">
                    @csrf
                    <button type="submit" class="w-full {{ $btn }} bg-white text-slate-700 ring-1 ring-slate-300 hover:bg-slate-50">捨棄草稿</button>
                </form>
            @endif

            @if ($statusTargets->isNotEmpty())
                <div class="rounded-lg bg-white p-4 shadow-sm">
                    <h2 class="mb-2 text-sm font-semibold text-slate-700">狀態：{{ $statusLabel }}</h2>
                    <div class="flex flex-wrap gap-2">
                        @foreach ($statusTargets as $t)
                            <form method="POST" action="{{ route('flows.status', $flow->id) }}" onsubmit="return confirm(@js('確定變更為「'.$t['name'].'」？'))">
                                @csrf
                                <input type="hidden" name="to" value="{{ $t['code'] }}">
                                <button type="submit" class="{{ $btn }} bg-slate-100 hover:bg-slate-200">設為{{ $t['name'] }}</button>
                            </form>
                        @endforeach
                    </div>
                </div>
            @endif

            <div class="rounded-lg bg-white p-4 shadow-sm">
                <h2 class="mb-2 text-sm font-semibold text-slate-700">版本紀錄</h2>
                <ul class="space-y-2 text-sm">
                    @if ($canSeeDraft && $flow->hasDraft())
                        <li class="{{ $isDraft ? 'font-semibold' : '' }}">
                            <a href="{{ route('flows.show', ['id' => $flow->id, 'v' => 'draft']) }}" class="text-amber-700 hover:underline">草稿</a>
                            <span class="text-xs text-slate-500">{{ $flow->draft_updated_at?->timezone('Asia/Taipei')->format('m-d H:i') }} {{ $flow->draftEditor?->name }}</span>
                        </li>
                    @endif
                    @forelse ($revisions as $rev)
                        <li class="{{ ! $isDraft && $rev->version === $doc['version'] ? 'font-semibold' : '' }}">
                            <div class="flex justify-between gap-2">
                                <a href="{{ route('flows.show', ['id' => $flow->id, 'v' => $rev->version]) }}" class="text-indigo-700 hover:underline">v{{ $rev->version }}</a>
                                <span class="truncate text-xs text-slate-500">{{ $rev->created_at?->timezone('Asia/Taipei')->format('m-d H:i') }} {{ $rev->author?->name }}</span>
                            </div>
                            @if ($rev->note)<p class="text-xs font-normal text-slate-500">{{ $rev->note }}</p>@endif
                        </li>
                    @empty
                        <li class="text-xs text-slate-500">尚未發布。</li>
                    @endforelse
                </ul>
            </div>

            @if ($isDraft && $snapshots->count() > 1)
                <div class="rounded-lg bg-white p-4 shadow-sm">
                    <h2 class="mb-1 text-sm font-semibold text-slate-700">草稿儲存紀錄</h2>
                    <p class="mb-2 text-xs text-slate-500">存錯了可以還原到之前某次儲存（不算版本，發布後清除）。</p>
                    <ul class="space-y-1 text-sm">
                        @foreach ($snapshots as $i => $s)
                            <li class="flex items-center justify-between gap-2">
                                <span class="text-xs text-slate-600">第 {{ $s->draft_rev }} 次　{{ $s->created_at?->timezone('Asia/Taipei')->format('m-d H:i') }} {{ $s->author?->name }}</span>
                                @if ($i === 0)
                                    <span class="text-xs text-slate-400">目前</span>
                                @elseif ($canEditDraft)
                                    <form method="POST" action="{{ route('flows.snapshots.restore', [$flow->id, $s->id]) }}" onsubmit="return confirm(@js('把草稿還原成第 '.$s->draft_rev.' 次儲存的內容？'))">
                                        @csrf
                                        <button type="submit" class="text-xs text-indigo-700 hover:underline">還原</button>
                                    </form>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endif

            @if ($canManage)
                <form method="POST" action="{{ route('flows.editors', $flow->id) }}" class="rounded-lg bg-white p-4 shadow-sm">
                    @csrf
                    <h2 class="mb-1 text-sm font-semibold text-slate-700">指定編輯者</h2>
                    <p class="mb-2 text-xs text-slate-500">被指定的成員可以修改草稿，但發布仍需 PM。PM 一律可以編輯。</p>
                    @php $picked = $editors->pluck('user_id')->all(); @endphp
                    <div class="max-h-48 space-y-1 overflow-y-auto text-sm">
                        @foreach ($editorCandidates as $c)
                            <label class="flex items-center gap-2">
                                <input type="checkbox" name="user_ids[]" value="{{ $c->id }}" @checked(in_array($c->id, $picked, true)) class="rounded border-slate-300">
                                {{ $c->name }} <span class="text-xs text-slate-400">{{ $c->account }}</span>
                            </label>
                        @endforeach
                    </div>
                    <button type="submit" class="mt-2 {{ $btn }} bg-slate-100 hover:bg-slate-200">儲存編輯者</button>
                </form>
            @endif

            @if ($canEditDraft && $doc['format'] === 'mermaid')
                @include('flows._upload', ['flow' => $flow])
            @endif

            @if ($canDelete)
                <form method="POST" action="{{ route('flows.destroy', $flow->id) }}" onsubmit="return confirm(@js('確定刪除「'.$flow->title.'」？會移到回收筒，專案管理員可以還原。'))">
                    @csrf @method('DELETE')
                    <button type="submit" class="w-full text-sm text-red-600 hover:underline">刪除流程圖</button>
                </form>
            @endif
        </aside>
    </div>
@endsection
