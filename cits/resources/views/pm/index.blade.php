@extends('layouts.app')
@section('title', '問題管理')
@php
    $keep = fn (array $own) => collect(request()->except($own))->filter(fn ($v) => is_scalar($v));
    $pickedTags = array_map('strval', (array) ($f['tags'] ?? []));
    $internalTab = $f['itab'] ?? 'issues';
    $input = 'rounded-md border border-slate-300 bg-white px-2 py-1.5 text-sm focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500';
@endphp
@section('content')
    <div class="mb-4 flex items-center justify-between">
        <h1 class="text-xl font-semibold">問題管理</h1>
        @if ($canExport)
            <a href="{{ route('pm.export', request()->query()) }}" class="rounded-md border border-emerald-600 px-3 py-1.5 text-sm font-medium text-emerald-700 hover:bg-emerald-50" title="依目前篩選條件匯出客戶單與所選頁籤">匯出 Excel</a>
        @endif
    </div>

    @include('collab._saved_queries', ['scope' => 'pm'])

    {{-- 客戶問題 --}}
    <section class="mb-8 rounded-lg bg-white p-4 shadow-sm">
        <div class="mb-3 flex flex-wrap items-center gap-3">
            <h2 class="text-base font-semibold">客戶問題</h2>
            <span class="rounded-full bg-amber-100 px-2.5 py-0.5 text-xs font-medium text-amber-800">待接收 {{ $counters['unaccepted'] }}</span>
            <span class="rounded-full bg-red-100 px-2.5 py-0.5 text-xs font-medium text-red-800">需處置 {{ $counters['action'] }}</span>
            <span class="rounded-full bg-sky-100 px-2.5 py-0.5 text-xs font-medium text-sky-800">修訂未確認 {{ $counters['revised'] }}</span>
            <a href="{{ route('pm.index', array_merge(request()->except(['tp', 'ni', 'oa']), ['ni' => 'waiting', 'oa' => 0])) }}" class="rounded-full bg-rose-100 px-2.5 py-0.5 text-xs font-medium text-rose-800 hover:ring-1 hover:ring-rose-300">等待客戶補充 {{ $counters['waiting'] }}</a>
            <a href="{{ route('pm.index', array_merge(request()->except(['tp', 'ni', 'oa']), ['ni' => 'answered', 'oa' => 0])) }}" class="rounded-full bg-emerald-100 px-2.5 py-0.5 text-xs font-medium text-emerald-800 hover:ring-1 hover:ring-emerald-300">客戶已補充 {{ $counters['answered'] }}</a>
        </div>
        <form method="GET" action="{{ route('pm.index') }}" class="mb-3 flex flex-wrap items-center gap-3">
            @foreach ($keep(['oa', 'tt', 'tq', 'ni', 'tp']) as $k => $v)<input type="hidden" name="{{ $k }}" value="{{ $v }}">@endforeach
            @foreach ($pickedTags as $tg)<input type="hidden" name="tags[]" value="{{ $tg }}">@endforeach
            <input type="hidden" name="oa" value="0">
            <select name="tt" class="{{ $input }}" onchange="this.form.submit()" title="客戶單範圍">
                @foreach ($ticketScopes as $code => $name)<option value="{{ $code }}" @selected($ticketScope === $code)>{{ $name }}</option>@endforeach
                <option value="all" @selected($ticketScope === 'all')>全部</option>
            </select>
            @if ($onlyActionApplies)
                <label class="flex items-center gap-1.5 text-sm"><input type="checkbox" name="oa" value="1" @checked($f['oa'] === '1') class="rounded border-slate-300" onchange="this.form.submit()"> 只看需處理</label>
            @endif
            <select name="ni" class="{{ $input }}" onchange="this.form.submit()">
                <option value="">補充資料：全部</option>
                <option value="waiting" @selected(($f['ni'] ?? '') === 'waiting')>等待客戶補充</option>
                <option value="answered" @selected(($f['ni'] ?? '') === 'answered')>客戶已補充（未看）</option>
            </select>
            <input type="text" name="tq" value="{{ $f['tq'] ?? '' }}" placeholder="單號或標題" class="{{ $input }}">
            <button class="rounded-md bg-slate-100 px-3 py-1.5 text-sm hover:bg-slate-200">搜尋</button>
        </form>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-slate-200 text-sm">
                <thead class="bg-slate-50 text-left text-xs text-slate-500">
                    <tr><th class="px-3 py-2"></th><th class="px-3 py-2">單號</th><th class="px-3 py-2">標題</th><th class="px-3 py-2">狀態</th><th class="px-3 py-2">提醒</th><th class="px-3 py-2">開單者</th><th class="px-3 py-2">更新</th><th class="px-3 py-2">結案</th></tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse ($tickets as $t)
                        <tr class="{{ $t->accepted_at === null || $t->needs_pm_action ? 'bg-amber-50/50' : '' }}">
                            <td class="px-3 py-2"><a href="{{ route('pm.tickets.show', $t->id) }}" class="rounded-md bg-indigo-600 px-2.5 py-1 text-xs font-medium text-white hover:bg-indigo-700">瀏覽</a></td>
                            <td class="whitespace-nowrap px-3 py-2 font-medium">{{ $t->ticket_no }}</td>
                            <td class="px-3 py-2">{{ $t->title }}</td>
                            <td class="px-3 py-2"><x-badge :status="$t->status" scope="ticket" /></td>
                            <td class="space-x-1 px-3 py-2">
                                @if ($t->accepted_at === null)<span class="rounded bg-amber-100 px-1.5 py-0.5 text-xs text-amber-800">待接收</span>@endif
                                @if ($t->needs_pm_action)<span class="rounded bg-red-100 px-1.5 py-0.5 text-xs text-red-800">需處置</span>@endif
                                @if ($t->unacked_revisions_count > 0)<span class="rounded bg-sky-100 px-1.5 py-0.5 text-xs text-sky-800">修訂 {{ $t->unacked_revisions_count }}</span>@endif
                                @if (($needinfoStates[$t->id] ?? null) === 'waiting')<span class="rounded bg-rose-100 px-1.5 py-0.5 text-xs text-rose-800">等客戶補充</span>@endif
                                @if (($needinfoStates[$t->id] ?? null) === 'answered')<span class="rounded bg-emerald-100 px-1.5 py-0.5 text-xs text-emerald-800">客戶已補充</span>@endif
                            </td>
                            <td class="px-3 py-2">{{ $t->creator?->name }}</td>
                            <td class="whitespace-nowrap px-3 py-2 text-slate-500">{{ tw($t->updated_at) }}</td>
                            <td class="whitespace-nowrap px-3 py-2 text-slate-500">{{ $t->closed_at ? tw($t->closed_at) : '-' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="8" class="px-3 py-6 text-center text-slate-400">沒有符合條件的客戶單</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="mt-3">{{ $tickets->links() }}</div>
    </section>

    {{-- 延期申請 --}}
    @if ($can['approve'] && $dueRequests->isNotEmpty())
        <section class="mb-8 rounded-lg border border-amber-200 bg-amber-50 p-4">
            <h2 class="mb-2 text-base font-semibold text-amber-900">待審核的延期申請</h2>
            <ul class="space-y-2 text-sm">
                @foreach ($dueRequests as $r)
                    <li class="flex flex-wrap items-center gap-3 rounded bg-white p-2">
                        <a href="{{ route('issues.show', $r->issue_id) }}" class="font-medium text-indigo-700 hover:underline">{{ $r->issue?->issue_no }}</a>
                        <span>{{ $r->issue?->title }}</span>
                        <span class="text-slate-500">{{ $r->old_due?->format('Y-m-d') ?: '-' }} → <b>{{ $r->new_due->format('Y-m-d') }}</b></span>
                        <span class="text-slate-600">原因：{{ $r->reason }}（{{ $r->requester?->name }}）</span>
                        <span class="ml-auto flex gap-2">
                            @foreach ([1 => ['核准', 'bg-emerald-600 hover:bg-emerald-700'], 0 => ['駁回', 'bg-slate-500 hover:bg-slate-600']] as $v => [$lbl, $cls])
                                <form method="POST" action="{{ route('pm.due.review', $r->id) }}">@csrf
                                    <input type="hidden" name="approve" value="{{ $v }}">
                                    <button class="rounded-md px-3 py-1 text-xs font-medium text-white {{ $cls }}">{{ $lbl }}</button>
                                </form>
                            @endforeach
                        </span>
                    </li>
                @endforeach
            </ul>
        </section>
    @endif

    {{-- 內部問題清單 --}}
    <section id="internal-issues" class="rounded-lg bg-white p-4 shadow-sm">
        <div class="mb-3 flex flex-wrap items-center justify-between gap-3">
            <nav aria-label="內部問題" class="flex items-center gap-1">
                @foreach (['issues' => '內部問題清單', 'close' => '結案收尾'] as $key => $label)
                    <a href="{{ route('pm.index', array_merge(request()->query(), ['itab' => $key])) }}#internal-issues"
                       @if ($internalTab === $key) aria-current="page" @endif
                       @class(['rounded-md px-3 py-1.5 text-sm font-medium', 'bg-indigo-600 text-white' => $internalTab === $key, 'text-slate-600 hover:bg-slate-100' => $internalTab !== $key])>{{ $label }}</a>
                @endforeach
            </nav>
            @if ($can['create'])
                <div class="flex gap-2">
                    <a href="{{ route('pm.issues.import') }}" class="rounded-md border border-indigo-600 px-3 py-1.5 text-sm font-medium text-indigo-700 hover:bg-indigo-50" title="用標準範本的 Excel 一次建立多張內部單">Excel 匯入</a>
                    <a href="{{ route('pm.issues.create') }}" class="rounded-md bg-indigo-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-indigo-700">新增內部問題</a>
                </div>
            @endif
        </div>
        @if ($internalTab === 'close')
            <form method="GET" action="{{ route('pm.index') }}#internal-issues" class="mb-3 flex flex-wrap items-center gap-3">
                @foreach ($keep(['close_status', 'close_mine', 'close_q', 'close_page']) as $k => $v)<input type="hidden" name="{{ $k }}" value="{{ $v }}">@endforeach
                @foreach ($pickedTags as $tg)<input type="hidden" name="tags[]" value="{{ $tg }}">@endforeach
                <select name="close_status" aria-label="收尾狀態" class="{{ $input }}">
                    @foreach ($statusOptions as $code => $label)<option value="{{ $code }}" @selected($closeFilters['status'] === $code)>{{ $label }}</option>@endforeach
                </select>
                <input name="close_q" value="{{ $closeFilters['q'] }}" placeholder="單號或標題" aria-label="收尾單號或標題" class="{{ $input }}">
                <label class="flex items-center gap-1.5 text-sm"><input type="checkbox" name="close_mine" value="1" @checked($closeFilters['mine'])>只看我待確認</label>
                <button class="rounded-md bg-slate-100 px-3 py-1.5 text-sm hover:bg-slate-200">篩選</button>
            </form>
            @include('dev._close-rounds')
        @else
        <form method="GET" action="{{ route('pm.index') }}#internal-issues" class="mb-3 grid grid-cols-2 gap-2 md:flex md:flex-wrap md:items-center">
            @foreach ($keep(['stage', 'status', 'origin', 'q', 'sort', 'tags', 'page']) as $k => $v)<input type="hidden" name="{{ $k }}" value="{{ $v }}">@endforeach
            <select name="stage" class="{{ $input }}">
                <option value="">全部階段</option>
                @foreach ($stages as $s)<option value="{{ $s }}" @selected(($f['stage'] ?? '') === $s)>{{ $s }}</option>@endforeach
            </select>
            <select name="status" class="{{ $input }}">
                <option value="">全部狀態</option>
                @foreach ($statuses as $s)<option value="{{ $s->code }}" @selected(($f['status'] ?? '') === $s->code)>{{ $s->name }}</option>@endforeach
            </select>
            <select name="origin" class="{{ $input }}">
                <option value="">全部來源</option>
                @foreach ($origins as $o)<option value="{{ $o }}" @selected(($f['origin'] ?? '') === $o)>{{ $originLabels[$o] ?? $o }}</option>@endforeach
            </select>
            <select name="sort" class="{{ $input }}">
                @foreach (['created' => '建立時間', 'last' => '最後更新', 'severity' => '嚴重度', 'due' => '預計完工'] as $k => $l)
                    <option value="{{ $k }}" @selected(($f['sort'] ?? 'created') === $k)>排序：{{ $l }}</option>
                @endforeach
            </select>
            <input type="text" name="q" value="{{ $f['q'] ?? '' }}" placeholder="單號或標題" class="{{ $input }}">
            @if ($tagOptions || $pickedTags)
                <details class="relative">
                    <summary class="{{ $input }} cursor-pointer list-none">標籤{{ $pickedTags ? '（'.count($pickedTags).'）' : '' }} ▾</summary>
                    <div class="absolute z-20 mt-1 max-h-64 w-56 space-y-1 overflow-y-auto rounded-md border border-slate-200 bg-white p-2 shadow-lg">
                        <p class="text-xs text-slate-400">符合任一個勾選的標籤</p>
                        @foreach ($tagLabels as $code => $name)
                            @if (isset($tagOptions[$code]) || in_array($code, $pickedTags, true))
                                <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="tags[]" value="{{ $code }}" @checked(in_array($code, $pickedTags, true)) class="rounded border-slate-300"> {{ $name }}</label>
                            @endif
                        @endforeach
                    </div>
                </details>
            @endif
            <button class="rounded-md bg-slate-100 px-3 py-1.5 text-sm hover:bg-slate-200">篩選</button>
        </form>
        @php
            $pickMeeting = $meetingTargets->isNotEmpty() || $canCreateMeeting;
            $pickRows = $pickMeeting || $can['delete'];
        @endphp
        @if ($pickRows && $issues->isNotEmpty())
        <div class="mb-3 flex flex-wrap items-center gap-2">
        @endif
        @if ($pickMeeting && $issues->isNotEmpty())
            <form id="meet-bulk" method="POST" action="{{ route('meetings.add-issues') }}" class="flex flex-wrap items-center gap-2 rounded-md bg-indigo-50 px-3 py-2 text-sm"
                  onsubmit="if (!this.querySelector('[name=meeting_id]').value) { alert('請選擇會議'); return false; } if (!document.querySelector('[form=meet-bulk][name=\'issue_ids[]\']:checked')) { alert('請先勾選問題單'); return false; }">
                @csrf
                <span class="text-indigo-900">排入會議：</span>
                <select name="meeting_id" class="{{ $input }}">
                    <option value="">選擇會議…</option>
                    @foreach ($meetingTargets as $mt)
                        <option value="{{ $mt->id }}">{{ tw($mt->start_at, 'm-d H:i') }} {{ $mt->title }}</option>
                    @endforeach
                    @if ($canCreateMeeting)<option value="new">＋ 新會議…</option>@endif
                </select>
                <button type="submit" data-meet-btn class="rounded-md bg-indigo-600 px-3 py-1.5 font-medium text-white hover:bg-indigo-700 disabled:opacity-50" disabled>排入（0）</button>
                <span class="text-xs text-indigo-800/70">勾選左側問題單（本頁）</span>
            </form>
        @endif
        @if ($can['delete'] && $issues->isNotEmpty())
            <form method="POST" action="{{ route('issues.bulk-destroy') }}" data-del-form class="ml-auto">
                @csrf
                <button type="submit" data-del-btn class="rounded-md border border-red-300 px-3 py-1.5 text-sm text-red-700 hover:bg-red-50 disabled:opacity-50" disabled
                        title="只有新建狀態、且你是建立者或內部 PM 的單會被刪除，其它會略過">刪除選取（0）</button>
            </form>
        @endif
        @if ($pickRows && $issues->isNotEmpty())
        </div>
        @endif
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-slate-200 text-sm">
                <thead class="bg-slate-50 text-left text-xs text-slate-500">
                    <tr>@if ($pickRows && $issues->isNotEmpty())<th class="w-8 px-3 py-2"><input type="checkbox" data-meet-all class="rounded border-slate-300" title="全選本頁"></th>@endif<th class="px-3 py-2">單號</th><th class="px-3 py-2">標題</th><th class="px-3 py-2">開單時間</th><th class="px-3 py-2">來源</th><th class="px-3 py-2">嚴重度</th><th class="px-3 py-2">狀態</th><th class="px-3 py-2">DEV<br>首次派開發</th><th class="px-3 py-2">Test<br>最近派測試（派測來源）</th><th class="px-3 py-2">預計完工</th><th class="px-3 py-2">客戶單</th></tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse ($issues as $i)
                        <tr class="cursor-pointer hover:bg-slate-50" onclick="location.href='{{ route('issues.show', $i->id) }}'">
                            @if ($pickRows)
                                <td class="px-3 py-2" onclick="event.stopPropagation()"><input type="checkbox" form="meet-bulk" name="issue_ids[]" value="{{ $i->id }}" data-meet-pick class="rounded border-slate-300"></td>
                            @endif
                            <td class="whitespace-nowrap px-3 py-2 font-medium text-indigo-700">{{ $i->issue_no }}</td>
                            <td class="px-3 py-2">
                                {{ $i->title }}
                                @if (! empty($blockers[$i->id]))<span class="ml-1 rounded bg-orange-100 px-1.5 py-0.5 text-xs text-orange-800" title="需等待 {{ implode('、', $blockers[$i->id]) }}">等待 {{ count($blockers[$i->id]) }} 張</span>@endif
                                @foreach ($issueTags[$i->id] ?? [] as $tg)<span class="ml-1 rounded bg-violet-100 px-1.5 py-0.5 text-xs text-violet-800">{{ $tagLabels[$tg] ?? $tg }}</span>@endforeach
                            </td>
                            <td class="whitespace-nowrap px-3 py-2">{{ $timeline[$i->id]['created_at'] }}</td>
                            <td class="whitespace-nowrap px-3 py-2">
                                {{ $originLabels[$i->origin] ?? $i->origin }}
                                @if (! in_array($i->origin, $externalOrigins, true))<span class="ml-1 rounded bg-slate-200 px-1.5 py-0.5 text-xs text-slate-600">內部</span>@endif
                            </td>
                            <td class="px-3 py-2">@include('pm._severity', ['n' => $i->severity])</td>
                            <td class="px-3 py-2"><x-badge :status="$i->status" scope="issue" /></td>
                            <td class="whitespace-nowrap px-3 py-2">{{ $i->dev?->name ?: '-' }}<div class="text-xs text-slate-500">{{ $timeline[$i->id]['dev_dispatched_at'] }}</div></td>
                            <td class="whitespace-nowrap px-3 py-2">{{ $i->tester?->name ?: '-' }}<div class="text-xs text-slate-500">{{ $timeline[$i->id]['test_dispatched_at'] }} @if ($timeline[$i->id]['dispatch_source'] !== '—')（{{ $timeline[$i->id]['dispatch_source'] }}）@endif</div></td>
                            <td class="whitespace-nowrap px-3 py-2">{{ $i->current_due?->format('Y-m-d') ?: '-' }}</td>
                            <td class="px-3 py-2">@forelse ($i->tickets as $tk)<a href="{{ route('pm.tickets.show', $tk->id) }}" class="mr-1 whitespace-nowrap hover:underline">{{ $tk->ticket_no }}</a><x-badge :status="$tk->status" scope="ticket" />@if (! $loop->last)<br>@endif @empty - @endforelse</td>
                        </tr>
                    @empty
                        <tr><td colspan="10" class="px-3 py-6 text-center text-slate-400">沒有符合條件的內部單</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="mt-3">{{ $issues->links() }}</div>
        @endif
    </section>
@endsection
@push('scripts')
    <script>
        (() => {
            const btn = document.querySelector('[data-meet-btn]');
            const del = document.querySelector('[data-del-btn]');
            if (!btn && !del) return;
            const picks = [...document.querySelectorAll('[data-meet-pick]')];
            const checked = () => picks.filter((p) => p.checked);
            const sync = () => {
                const n = checked().length;
                if (btn) {
                    btn.disabled = n === 0;
                    btn.textContent = `排入（${n}）`;
                }
                if (del) {
                    del.disabled = n === 0;
                    del.textContent = `刪除選取（${n}）`;
                }
            };
            document.querySelector('[data-del-form]')?.addEventListener('submit', (e) => {
                const ids = checked().map((p) => p.value);
                if (!ids.length || !confirm(`將勾選的 ${ids.length} 張內部單移到回收筒？\n不是新建狀態、或你不能刪的單會略過。`)) {
                    e.preventDefault();
                    return;
                }
                e.target.querySelectorAll('input[name="issue_ids[]"]').forEach((i) => i.remove());
                ids.forEach((id) => e.target.insertAdjacentHTML('beforeend', `<input type="hidden" name="issue_ids[]" value="${Number(id)}">`));
            });
            picks.forEach((p) => p.addEventListener('change', sync));
            document.querySelector('[data-meet-all]')?.addEventListener('change', (e) => {
                picks.forEach((p) => (p.checked = e.target.checked));
                sync();
            });
            sync();
        })();
    </script>
@endpush
