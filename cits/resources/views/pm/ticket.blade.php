@extends('layouts.app')
@section('title', $ticket->ticket_no)
@push('head')
    @vite('resources/js/editor/index.js')
@endpush
@php
    $input = 'w-full rounded-md border border-slate-300 bg-white px-2 py-1.5 text-sm focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500';
    $btn = 'rounded-md px-3 py-1.5 text-sm font-medium text-white';
    $card = 'rounded-lg bg-white p-4 shadow-sm';
    $pid = $ticket->project_id;
@endphp
@section('content')
    <div class="mb-4 flex flex-wrap items-center gap-3">
        <a href="{{ route('pm.index') }}" class="text-sm text-slate-500 hover:text-indigo-700">&larr; 問題管理</a>
        <h1 class="text-xl font-semibold">{{ $ticket->ticket_no }}　{{ $ticket->title }}</h1>
        <x-badge :status="$ticket->status" scope="ticket" />
        @if ($ticket->needs_pm_action)<span class="rounded bg-red-100 px-2 py-0.5 text-xs text-red-800">需處置</span>@endif
        @if ($ticket->dispatched_at)<span class="rounded bg-slate-200 px-2 py-0.5 text-xs text-slate-600">已拋單</span>@endif
        @if ($needinfoOpen)<span class="rounded bg-rose-100 px-2 py-0.5 text-xs text-rose-800">等待客戶補充</span>@endif
        <span class="ml-auto">@include('collab._watch', ['action' => route('collab.tickets.watch', $ticket->id), 'watching' => $watching, 'watchers' => $watchers])</span>
    </div>

    <div class="grid gap-4 lg:grid-cols-3">
        <div class="space-y-4 lg:col-span-2">
            <section class="{{ $card }}">
                <dl class="mb-3 grid grid-cols-2 gap-x-4 gap-y-1 text-sm md:grid-cols-4">
                    <div><dt class="text-xs text-slate-500">類型</dt><dd>{{ app(\App\Services\WorkflowService::class)->lookupLabel('ticket_type', $ticket->ticket_type) }}</dd></div>
                    <div><dt class="text-xs text-slate-500">異常分類</dt><dd>{{ $ticket->anomaly_category ? app(\App\Services\WorkflowService::class)->lookupLabel('anomaly_category', $ticket->anomaly_category) : '-' }}</dd></div>
                    <div><dt class="text-xs text-slate-500">MES 頁面</dt><dd>{{ $ticket->mesPage?->name ?: '-' }}</dd></div>
                    <div><dt class="text-xs text-slate-500">開單者</dt><dd>{{ $ticket->creator?->name }}</dd></div>
                    <div><dt class="text-xs text-slate-500">版本</dt><dd>第 {{ $ticket->current_rev }} 版</dd></div>
                    <div><dt class="text-xs text-slate-500">建立時間</dt><dd>{{ tw($ticket->created_at) }}</dd></div>
                    <div><dt class="text-xs text-slate-500">接收時間</dt><dd>{{ tw($ticket->accepted_at) ?: '-' }}</dd></div>
                    <div><dt class="text-xs text-slate-500">重新提出</dt><dd>{{ $ticket->reopen_count }} 次</dd></div>
                </dl>
                <div class="prose max-w-none border-t border-slate-100 pt-3 text-sm">{!! $ticket->content_html !!}</div>
            </section>

            @foreach ($ticket->reopens as $r)
                <section class="{{ $card }} border-l-4 border-red-300">
                    <h3 class="mb-1 text-sm font-semibold text-red-700">客戶提出仍有異常（第 {{ $r->round }} 次，{{ tw($r->created_at) }}）</h3>
                    <div class="prose max-w-none text-sm">{!! $r->content_html !!}</div>
                </section>
            @endforeach

            <section class="{{ $card }}">
                <div class="mb-2 flex flex-wrap items-center gap-3">
                    <h2 class="text-base font-semibold">修訂紀錄</h2>
                    @if ($unackedCount > 0)
                        <span class="rounded bg-sky-100 px-2 py-0.5 text-xs text-sky-800">{{ $unackedCount }} 筆未確認</span>
                        @if ($canAck)<form method="POST" action="{{ route('pm.tickets.ack', $ticket->id) }}">@csrf
                            <button class="{{ $btn }} bg-sky-600 hover:bg-sky-700">確認已讀修訂</button>
                        </form>@endif
                    @endif
                </div>
                <ul class="divide-y divide-slate-100 text-sm">
                    @foreach ($ticket->revisions->sortByDesc('rev_no') as $rev)
                        <li class="py-2">
                            <details>
                                <summary class="cursor-pointer">
                                    第 {{ $rev->rev_no }} 版　{{ $rev->title }}　<span class="text-slate-500">{{ tw($rev->created_at) }} {{ $rev->author?->name }}</span>
                                    @if ($rev->rev_no > 1)
                                        <span class="ml-1 rounded px-1.5 py-0.5 text-xs {{ $rev->pm_ack_at ? 'bg-emerald-100 text-emerald-800' : 'bg-sky-100 text-sky-800' }}">{{ $rev->pm_ack_at ? '已確認' : '未確認' }}</span>
                                        <a href="{{ route('tickets.revisions.diff', [$ticket->id, $rev->rev_no]) }}" class="ml-1 text-xs text-indigo-600 hover:underline">與前一版比較</a>
                                    @endif
                                </summary>
                                <div class="prose mt-2 max-w-none rounded bg-slate-50 p-3">{!! $rev->content_snapshot !!}</div>
                            </details>
                        </li>
                    @endforeach
                </ul>
            </section>

            <section class="{{ $card }}">
                <h2 class="mb-2 text-base font-semibold">客戶留言</h2>
                <div class="space-y-3">
                    @forelse ($ticket->comments as $c)
                        <div class="rounded border border-slate-100 p-3 text-sm {{ $c->author?->isClient() ? 'bg-white' : 'bg-indigo-50/40' }}">
                            <div class="mb-1 flex flex-wrap items-center gap-2 text-xs text-slate-500">
                                <b class="text-slate-700">{{ $c->author?->name }}</b>
                                <span>{{ app(\App\Services\WorkflowService::class)->lookupLabel('comment_kind', $c->kind) }}</span>
                                <span>{{ tw($c->created_at) }}</span>
                            </div>
                            <div class="prose max-w-none">{!! $c->content_html !!}</div>
                            @if ($canQuote && $c->author?->isClient() && $ticket->issues->isNotEmpty())
                                <form method="POST" action="{{ route('pm.tickets.quote', $ticket->id) }}" class="mt-2 flex flex-wrap items-center gap-2">@csrf
                                    <input type="hidden" name="comment_id" value="{{ $c->id }}">
                                    <select name="issue_id" class="{{ $input }} max-w-xs" required>
                                        @foreach ($ticket->issues as $li)<option value="{{ $li->id }}">{{ $li->issue_no }} {{ $li->title }}</option>@endforeach
                                    </select>
                                    <button class="rounded-md bg-slate-100 px-3 py-1.5 text-xs hover:bg-slate-200">引用到內部單</button>
                                </form>
                            @endif
                        </div>
                    @empty
                        <p class="text-sm text-slate-400">尚無留言</p>
                    @endforelse
                </div>
                @if ($ab['reply'])
                    <form method="POST" action="{{ route('pm.tickets.reply', $ticket->id) }}" class="mt-4 space-y-2">@csrf
                        <input type="hidden" name="draft" value="ticket:reply:{{ $ticket->id }}">
                        <label class="text-sm font-medium">回覆客戶</label>
                        <x-editor name="content_html" context="ticket:reply:{{ $ticket->id }}" />
                        <button class="{{ $btn }} bg-indigo-600 hover:bg-indigo-700">送出回覆</button>
                    </form>
                @endif
            </section>
        </div>

        <aside class="space-y-4">
            <section class="{{ $card }} space-y-3">
                <h2 class="text-base font-semibold">處理</h2>
                @if ($ab['locked_by_other'])<p class="rounded bg-amber-50 p-2 text-xs text-amber-800">客戶 {{ $ab['locked_by_other'] }} 正在編輯中。</p>@endif
                @if ($ab['accept'])
                    <form method="POST" action="{{ route('pm.tickets.accept', $ticket->id) }}">@csrf
                        <button class="{{ $btn }} w-full bg-emerald-600 hover:bg-emerald-700">接收客戶單</button>
                    </form>
                @endif
                @if (! $ab['accept'] && ! $canCreateIssue && ! $canLink && ! $ab['settle'] && ! $ab['reply'])
                    <p class="text-sm text-slate-400">目前沒有可執行的操作</p>
                @endif
            </section>

            {{-- needinfo --}}
            @if ($needinfoOpen || $canNeedinfo || $needinfoHistory->isNotEmpty())
                <section class="{{ $card }}" id="needinfo">
                    <h2 class="mb-2 text-base font-semibold">要求客戶補充資料</h2>
                    @if ($needinfoOpen)
                        <div class="rounded border border-rose-200 bg-rose-50 p-2 text-sm">
                            <div class="text-xs text-rose-700">等待中・{{ $needinfoOpen->requester?->name }}・{{ tw($needinfoOpen->requested_at) }}</div>
                            <div class="mt-1 whitespace-pre-line">{{ $needinfoOpen->note }}</div>
                        </div>
                        @if ($canNeedinfo)
                            <form method="POST" action="{{ route('collab.needinfo.withdraw', $ticket->id) }}" class="mt-2" onsubmit="return confirm('取消這個補充要求？')">@csrf @method('DELETE')
                                <button class="text-xs text-slate-600 underline hover:text-slate-900">取消要求</button>
                            </form>
                        @endif
                    @elseif ($canNeedinfo)
                        <form method="POST" action="{{ route('collab.needinfo.request', $ticket->id) }}" class="space-y-2">@csrf
                            <textarea name="note" rows="3" maxlength="{{ \App\Services\Collab\NeedinfoService::NOTE_MAX }}" required placeholder="要客戶提供什麼（截圖、log、重現步驟…），客戶會看到這段文字" class="{{ $input }}">{{ old('note') }}</textarea>
                            <button class="{{ $btn }} w-full bg-rose-600 hover:bg-rose-700">通知客戶補充</button>
                            <p class="text-xs text-slate-500">不會改變客戶單狀態；客戶留言或修訂內容後自動解除並通知你。</p>
                        </form>
                    @endif
                    @if ($needinfoHistory->whereNotNull('resolved_at')->isNotEmpty())
                        <details class="mt-2 text-xs text-slate-600">
                            <summary class="cursor-pointer">過去的要求（{{ $needinfoHistory->whereNotNull('resolved_at')->count() }}）</summary>
                            <ul class="mt-1 space-y-1">
                                @foreach ($needinfoHistory->whereNotNull('resolved_at') as $n)
                                    <li class="rounded bg-slate-50 p-1.5"><span class="text-slate-400">{{ tw($n->requested_at) }}</span> {{ $n->note }}
                                        <span class="block text-slate-400">{{ $n->resolution === 'answered' ? '客戶已補充' : '已取消' }}・{{ $n->resolver?->name }}・{{ tw($n->resolved_at) }}</span></li>
                                @endforeach
                            </ul>
                        </details>
                    @endif
                </section>
            @endif

            @if ($ab['settle'])
                <section class="{{ $card }}">
                    <h2 class="mb-2 text-base font-semibold">PM 處置</h2>
                    <p class="mb-2 text-xs text-slate-500">此單需要處置：可直接處置，或於下方建立／連結內部單。</p>
                    <form method="POST" action="{{ route('pm.tickets.settle', $ticket->id) }}" class="space-y-2">@csrf
                        <label class="flex items-center gap-2 text-sm"><input type="radio" name="action" value="pending" checked> 轉待結案</label>
                        <label class="flex items-center gap-2 text-sm"><input type="radio" name="action" value="cancel"> 取消客戶單</label>
                        <textarea name="note" rows="3" required maxlength="500" placeholder="給客戶的說明（必填）" class="{{ $input }}">{{ old('note') }}</textarea>
                        <button class="{{ $btn }} bg-slate-700 hover:bg-slate-800">送出處置</button>
                    </form>
                </section>
            @endif

            @if ($canCreateIssue)
                <section class="{{ $card }}" x-data="{ open: {{ $errors->any() ? 'true' : 'false' }} }">
                    <button type="button" class="flex w-full items-center justify-between text-base font-semibold" @click="open = !open">建立內部單 <span x-text="open ? '－' : '＋'"></span></button>
                    <form x-show="open" x-cloak method="POST" action="{{ route('pm.tickets.issue', $ticket->id) }}" class="mt-3 space-y-3">@csrf
                        <input type="hidden" name="draft" value="issue:fromticket:{{ $ticket->id }}">
                        <div><label class="text-xs text-slate-500">標題</label><input name="title" required maxlength="200" value="{{ old('title', $ticket->title) }}" class="{{ $input }}">@include('collab._similar', ['url' => route('collab.similar.internal')])</div>
                        <div class="grid grid-cols-2 gap-2">
                            <div><label class="text-xs text-slate-500">處理類型</label>
                                <select name="process_type" class="{{ $input }}"><option value="">-</option>
                                    @foreach ($processTypes as $p)<option value="{{ $p->code }}" @selected(old('process_type') === $p->code)>{{ $p->name }}</option>@endforeach
                                </select></div>
                            <div><label class="text-xs text-slate-500">嚴重度（1-5）</label>
                                <select name="severity" required class="{{ $input }}"><option value="">請選擇</option>
                                    @for ($k = 1; $k <= 5; $k++)<option value="{{ $k }}" @selected((int) old('severity') === $k)>{{ $k }}</option>@endfor
                                </select></div>
                        </div>
                        <div><label class="text-xs text-slate-500">MES 頁面</label>
                            <select name="mes_page_id" class="{{ $input }}"><option value="">-</option>
                                @foreach ($mesPages as $m)<option value="{{ $m->id }}" @selected((int) old('mes_page_id', $ticket->mes_page_id) === $m->id)>{{ $m->name }}</option>@endforeach
                            </select></div>
                        @if ($otherTickets->isNotEmpty())
                            <div><label class="text-xs text-slate-500">一併納入其他已接收客戶單（可複選）</label>
                                <select name="extra_ticket_ids[]" multiple size="4" class="{{ $input }}">
                                    @foreach ($otherTickets as $o)<option value="{{ $o->id }}">{{ $o->ticket_no }} {{ $o->title }}</option>@endforeach
                                </select></div>
                        @endif
                        <div><label class="text-xs text-slate-500">內容</label>
                            <x-editor name="content_html" :value="old('content_html', $ticket->content_html)" context="issue:fromticket:{{ $ticket->id }}" /></div>
                        <button class="{{ $btn }} bg-indigo-600 hover:bg-indigo-700">建立內部單</button>
                    </form>
                </section>
            @endif

            @if ($canLink)
                <section class="{{ $card }}">
                    <h2 class="mb-2 text-base font-semibold">連結到既有內部單</h2>
                    @if ($linkable->isEmpty())
                        <p class="text-sm text-slate-400">沒有可連結的內部單</p>
                    @else
                        <form method="POST" action="{{ route('pm.tickets.link', $ticket->id) }}" class="flex gap-2">@csrf
                            <select name="issue_id" required class="{{ $input }}">
                                @foreach ($linkable as $li)<option value="{{ $li->id }}">{{ $li->issue_no }} {{ $li->title }}</option>@endforeach
                            </select>
                            <button class="{{ $btn }} shrink-0 bg-indigo-600 hover:bg-indigo-700">連結</button>
                        </form>
                    @endif
                </section>
            @endif

            <section class="{{ $card }}">
                <h2 class="mb-2 text-base font-semibold">相關內部單</h2>
                <ul class="space-y-1 text-sm">
                    @forelse ($ticket->issues as $li)
                        <li class="flex items-center gap-2"><a href="{{ route('issues.show', $li->id) }}" class="font-medium text-indigo-700 hover:underline">{{ $li->issue_no }}</a><span class="truncate">{{ $li->title }}</span><x-badge :status="$li->status" scope="issue" /></li>
                    @empty
                        <li class="text-slate-400">尚未建立</li>
                    @endforelse
                </ul>
            </section>

            <section class="{{ $card }}">
                <h2 class="mb-2 text-base font-semibold">時間軸</h2>
                <ol class="space-y-2 border-l border-slate-200 pl-3 text-sm">
                    @foreach ($timeline as $e)
                        <li><div class="text-xs text-slate-500">{{ tw($e['at']) }} {{ $e['actor'] }}</div><div>{{ $e['label'] }}@if ($e['note'])<span class="text-slate-500">：{{ $e['note'] }}</span>@endif</div></li>
                    @endforeach
                </ol>
            </section>
        </aside>
    </div>
@endsection
