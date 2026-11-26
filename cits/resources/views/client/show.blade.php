@extends('layouts.app')
@section('title', $ticket->ticket_no)
@push('head')
    @vite('resources/js/editor/index.js')
@endpush
@section('content')
    <div x-data="{ modal: null }">
        <a href="{{ route('tickets.index') }}" class="mb-3 inline-block text-sm text-slate-500 hover:text-indigo-700">&larr; 返回列表</a>

        @if ($abilities['chain_locked'] && ! $abilities['close'] && ! app(\App\Services\WorkflowService::class)->status('ticket', $ticket->status)->is_terminal)
            <div class="mb-4 rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">已拋單處理中，內容已鎖定，可用下方補充留言提供資訊</div>
        @endif

        @if ($needinfoOpen)
            <div class="mb-4 rounded-md border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-900">
                <div class="font-medium">需要您補充資料</div>
                <div class="mt-1 whitespace-pre-line">{{ $needinfoOpen->note }}</div>
                <div class="mt-1 text-xs text-rose-700">{{ $needinfoOpen->requester?->name }}・{{ tw($needinfoOpen->requested_at) }}</div>
                @if ($abilities['comment'])
                    <a href="#comment-form" class="mt-2 inline-block text-xs font-medium text-rose-800 underline">在下方留言回覆</a>（留言或修訂內容後，PM 會收到通知）
                @endif
            </div>
        @endif

        <div class="grid gap-4 lg:grid-cols-3">
            <div class="space-y-4 lg:col-span-2">
                <section class="rounded-lg bg-white p-4 shadow-sm">
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="font-mono text-sm text-slate-500">{{ $ticket->ticket_no }}</span>
                        <x-badge :status="$ticket->status" scope="ticket" />
                        @if ($ticket->reopen_count > 0)<span class="rounded-full bg-rose-100 px-2 py-0.5 text-xs text-rose-700">重新開啟 {{ $ticket->reopen_count }} 次</span>@endif
                        <span class="rounded-full bg-slate-100 px-2 py-0.5 text-xs text-slate-600">rev {{ $ticket->current_rev }}</span>
                        <span class="ml-auto">@include('collab._watch', ['action' => route('collab.tickets.watch', $ticket->id), 'watching' => $watching])</span>
                    </div>
                    <h1 class="mt-2 text-xl font-semibold">{{ $ticket->title }}</h1>
                    @if ($progress)
                        <div class="mt-3">
                            <div class="mb-1 text-xs text-slate-400">處理進度@if ($progress['eta'])<span class="ml-2 text-slate-500">預計 {{ $progress['eta'] }} 更新正式環境</span>@endif</div>
                            <ol class="flex flex-wrap items-center gap-1 text-xs">
                                @foreach (array_values($progressSteps) as $n => $label)
                                    <li class="flex items-center gap-1">
                                        <span @class([
                                            'rounded-full px-2 py-0.5',
                                            'bg-emerald-100 text-emerald-800' => $n < $progress['index'],
                                            'bg-indigo-600 font-medium text-white' => $n === $progress['index'],
                                            'bg-slate-100 text-slate-400' => $n > $progress['index'],
                                        ])>{{ $label }}</span>
                                        @if (! $loop->last)<span class="text-slate-300">›</span>@endif
                                    </li>
                                @endforeach
                            </ol>
                        </div>
                    @endif
                    <dl class="mt-3 grid grid-cols-2 gap-x-4 gap-y-1 text-sm text-slate-600 md:grid-cols-4">
                        <div><dt class="text-xs text-slate-400">類型</dt><dd>{{ $types[$ticket->ticket_type] ?? $ticket->ticket_type }}@if ($ticket->anomaly_category) / {{ $categories[$ticket->anomaly_category] ?? $ticket->anomaly_category }}@endif</dd></div>
                        <div><dt class="text-xs text-slate-400">功能頁面</dt><dd>{{ $ticket->mesPage?->name ?? '-' }}</dd></div>
                        <div><dt class="text-xs text-slate-400">開立者</dt><dd>{{ $ticket->creator?->name }}</dd></div>
                        <div><dt class="text-xs text-slate-400">開立時間</dt><dd>{{ tw($ticket->created_at) }}</dd></div>
                        <div><dt class="text-xs text-slate-400">最後更新</dt><dd>{{ tw($ticket->updated_at) }}</dd></div>
                    </dl>
                    <div class="prose prose-sm mt-4 max-w-none break-words border-t border-slate-100 pt-4">{!! $ticket->content_html !!}</div>
                </section>

                @if ($ticket->revisions->count() > 1)
                    <section class="rounded-lg bg-white p-4 shadow-sm" x-data="{ open: false }">
                        <button type="button" class="flex w-full items-center justify-between text-left text-sm font-medium" @click="open = !open">
                            <span>修訂紀錄（{{ $ticket->revisions->count() }}）</span><span x-text="open ? '收合' : '展開'" class="text-xs text-slate-500"></span>
                        </button>
                        <div x-show="open" x-cloak class="mt-3 space-y-3">
                            @foreach ($ticket->revisions->sortByDesc('rev_no') as $rev)
                                <div class="rounded border border-slate-200 p-3">
                                    <div class="mb-1 text-xs text-slate-500">rev {{ $rev->rev_no }} · {{ tw($rev->created_at) }} · {{ $rev->author?->name }} · {{ $rev->title }}@if ($rev->rev_no > 1) · <a href="{{ route('tickets.revisions.diff', [$ticket->id, $rev->rev_no]) }}" class="text-indigo-600 hover:underline">與前一版比較</a>@endif</div>
                                    <div class="prose prose-sm max-w-none break-words">{!! $rev->content_snapshot !!}</div>
                                </div>
                            @endforeach
                        </div>
                    </section>
                @endif

                @if ($ticket->reopens->isNotEmpty())
                    <section class="rounded-lg bg-white p-4 shadow-sm">
                        <h2 class="mb-2 text-sm font-medium">異常提出紀錄</h2>
                        <div class="space-y-3">
                            @foreach ($ticket->reopens as $ro)
                                <div class="rounded border border-rose-200 bg-rose-50/40 p-3">
                                    <div class="mb-1 text-xs text-slate-500">第 {{ $ro->round }} 輪 · {{ tw($ro->created_at) }} · {{ $ro->author?->name }}</div>
                                    <div class="prose prose-sm max-w-none break-words">{!! $ro->content_html !!}</div>
                                </div>
                            @endforeach
                        </div>
                    </section>
                @endif

                <section class="rounded-lg bg-white p-4 shadow-sm">
                    <h2 class="mb-3 text-sm font-medium">留言（{{ $ticket->comments->count() }}）</h2>
                    <div class="space-y-3">
                        @forelse ($ticket->comments as $c)
                            <div class="rounded border border-slate-200 p-3">
                                <div class="mb-1 flex flex-wrap items-center gap-2 text-xs text-slate-500">
                                    <span class="font-medium text-slate-700">{{ $c->author?->name }}</span>
                                    <span class="rounded bg-slate-100 px-1.5">{{ $kinds[$c->kind] ?? $c->kind }}</span>
                                    <span>{{ tw($c->created_at) }}</span>
                                </div>
                                <div class="prose prose-sm max-w-none break-words">{!! $c->content_html !!}</div>
                                @if (! empty($readers[$c->id]))
                                    <div class="mt-2 text-xs text-slate-400">已讀：{{ implode('、', $readers[$c->id]) }}</div>
                                @endif
                            </div>
                        @empty
                            <x-empty>尚無留言</x-empty>
                        @endforelse
                    </div>

                    @if ($abilities['comment'])
                        <form id="comment-form" method="POST" action="{{ route('tickets.comment', $ticket->id) }}" class="mt-4 space-y-2 border-t border-slate-100 pt-4">
                            @csrf
                            <label class="block text-sm text-slate-700">類型
                                <select name="kind" class="ml-2 rounded-md border border-slate-300 py-1 text-sm">
                                    @foreach ($clientKinds as $code => $name)
                                        <option value="{{ $code }}">{{ $name }}</option>
                                    @endforeach
                                </select>
                            </label>
                            <x-editor name="content_html" value="" :context="'ticket:comment:'.$ticket->id" />
                            <button class="rounded-md bg-indigo-600 px-4 py-1.5 text-sm font-medium text-white hover:bg-indigo-700">送出留言</button>
                        </form>
                    @endif
                </section>
            </div>

            <aside class="space-y-4">
                @if ($abilities['edit'] || $abilities['cancel'] || $abilities['close'] || $abilities['reopen'])
                    <section class="space-y-2 rounded-lg bg-white p-4 shadow-sm">
                        <h2 class="text-sm font-medium">操作</h2>
                        @if ($abilities['edit'])
                            <a href="{{ route('tickets.edit', $ticket->id) }}" class="block rounded-md bg-indigo-600 px-3 py-2 text-center text-sm font-medium text-white hover:bg-indigo-700">{{ $abilities['edit_is_revision'] ? '提出修訂' : '編輯' }}</a>
                        @endif
                        @if ($abilities['close'])
                            <form method="POST" action="{{ route('tickets.close', $ticket->id) }}" onsubmit="return confirm('確定結案？')">
                                @csrf <button class="w-full rounded-md bg-emerald-600 px-3 py-2 text-sm font-medium text-white hover:bg-emerald-700">結案</button>
                            </form>
                        @endif
                        @if ($abilities['reopen'])
                            <button type="button" @click="modal = 'reopen'" class="w-full rounded-md bg-rose-600 px-3 py-2 text-sm font-medium text-white hover:bg-rose-700">提出異常</button>
                        @endif
                        @if ($abilities['cancel'])
                            <button type="button" @click="modal = 'cancel'" class="w-full rounded-md border border-red-300 px-3 py-2 text-sm text-red-700 hover:bg-red-50">取消客戶單</button>
                        @endif
                    </section>
                @endif

                <section class="rounded-lg bg-white p-4 shadow-sm">
                    <h2 class="mb-3 text-sm font-medium">流程時間軸</h2>
                    <ol class="relative ml-2 space-y-4 border-l border-slate-200 pl-4">
                        @forelse ($timeline as $ev)
                            <li class="relative">
                                <span class="absolute -left-[21px] top-1 h-2.5 w-2.5 rounded-full bg-indigo-500"></span>
                                <div class="text-sm font-medium">{{ $ev['label'] }}</div>
                                <div class="text-xs text-slate-500">{{ tw($ev['at']) }}@if ($ev['actor']) · {{ $ev['actor'] }}@endif</div>
                                @if ($ev['note'])<div class="mt-1 text-xs text-slate-600">{{ $ev['note'] }}</div>@endif
                            </li>
                        @empty
                            <li class="text-xs text-slate-400">尚無紀錄</li>
                        @endforelse
                    </ol>
                </section>
            </aside>
        </div>

        @if ($abilities['cancel'])
            <div x-show="modal === 'cancel'" x-cloak class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4">
                <form method="POST" action="{{ route('tickets.cancel', $ticket->id) }}" class="w-full max-w-md space-y-3 rounded-lg bg-white p-5 shadow-xl">
                    @csrf
                    <h3 class="font-semibold">取消客戶單</h3>
                    <label class="block text-sm">取消原因
                        <textarea name="reason" rows="3" maxlength="500" class="mt-1 block w-full rounded-md border border-slate-300 text-sm"></textarea>
                    </label>
                    <div class="flex justify-end gap-2">
                        <button type="button" @click="modal = null" class="rounded-md border border-slate-300 px-3 py-1.5 text-sm">返回</button>
                        <button class="rounded-md bg-red-600 px-3 py-1.5 text-sm text-white hover:bg-red-700">確認取消</button>
                    </div>
                </form>
            </div>
        @endif

        @if ($abilities['reopen'])
            <div x-show="modal === 'reopen'" x-cloak class="fixed inset-0 z-50 flex items-center justify-center overflow-y-auto bg-black/40 p-4">
                <form method="POST" action="{{ route('tickets.reopen', $ticket->id) }}" class="w-full max-w-2xl space-y-3 rounded-lg bg-white p-5 shadow-xl">
                    @csrf
                    <h3 class="font-semibold">提出異常</h3>
                    <p class="text-xs text-slate-500">請說明問題仍然存在的情況，案件將重新進入處理。</p>
                    <x-editor name="content_html" value="" :context="'ticket:reopen:'.$ticket->id" />
                    <div class="flex justify-end gap-2">
                        <button type="button" @click="modal = null" class="rounded-md border border-slate-300 px-3 py-1.5 text-sm">返回</button>
                        <button class="rounded-md bg-rose-600 px-3 py-1.5 text-sm text-white hover:bg-rose-700">送出</button>
                    </div>
                </form>
            </div>
        @endif
    </div>
@endsection
