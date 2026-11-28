{{-- One discussion item. Editable rows are bound by resources/js/meeting/items.js; $i null = blank template row. --}}
@php
    // no width here: Tailwind emits w-full after w-auto / w-28, so callers add their own
    $box = 'rounded-md border border-slate-300 bg-white px-2 py-1 text-sm focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500';
    $from = $isClient ? null : $i?->carriedFrom?->meeting; // the earlier meeting may not be shared with clients
    $closed = ! $isClient && $svc->issueClosed($i);
    $others = $i ? ($history[$i->id] ?? collect()) : collect();
@endphp
@if ($canEdit)
    <div data-item @if ($i) data-item-id="{{ $i->id }}" id="item-{{ $i->id }}" @endif class="rounded-md border border-slate-200 p-3">
        <div class="flex flex-wrap items-start gap-2">
            <span data-item-no class="mt-1 w-6 shrink-0 text-right text-sm text-slate-400"></span>
            <input type="text" data-field="topic" value="{{ $i?->topic }}" maxlength="300" placeholder="問題點（Enter 新增下一項）" class="{{ $box }} min-w-0 flex-1 font-medium">
            <select data-field="status" class="{{ $box }} w-auto" @disabled(! $i)>
                @foreach ($itemStatuses as $code => $name)
                    <option value="{{ $code }}" @selected($i?->status === $code)>{{ $name }}</option>
                @endforeach
            </select>
            <span class="flex shrink-0 gap-1">
                <button type="button" data-item-move="up" class="rounded px-1.5 text-slate-500 hover:bg-slate-100" title="上移">↑</button>
                <button type="button" data-item-move="down" class="rounded px-1.5 text-slate-500 hover:bg-slate-100" title="下移">↓</button>
                <button type="button" data-item-delete class="rounded px-1.5 text-red-600 hover:bg-red-50" title="刪除">✕</button>
            </span>
        </div>
        <div class="mt-2 grid gap-2 pl-8 md:grid-cols-2">
            <textarea data-field="discussion" rows="2" maxlength="5000" placeholder="討論內容" class="{{ $box }} w-full">{{ $i?->discussion }}</textarea>
            <textarea data-field="conclusion" rows="2" maxlength="5000" placeholder="結論／解決方法" class="{{ $box }} w-full">{{ $i?->conclusion }}</textarea>
        </div>
        @include('meetings._item-images', ['editable' => true, 'list' => $i ? $images->get($i->id, collect()) : collect()])
        <div class="mt-2 flex flex-wrap items-center gap-2 pl-8 text-sm">
            <label class="flex items-center gap-1 text-xs text-slate-500">負責人
                <select data-field="owner_id" class="{{ $box }} w-auto">
                    <option value="">—</option>
                    @foreach ($members as $mem)
                        <option value="{{ $mem->id }}" @selected($i?->owner_id === $mem->id)>{{ $mem->name }}</option>
                    @endforeach
                </select>
            </label>
            <label class="flex items-center gap-1 text-xs text-slate-500">期限
                <input type="date" data-field="due_date" value="{{ $i?->due_date?->format('Y-m-d') }}" class="{{ $box }} w-auto">
            </label>
            @unless ($isClient)
                <label class="flex items-center gap-1 text-xs text-slate-500">問題單
                    <input type="text" data-field="issue_no" value="{{ $i?->issue?->issue_no }}" maxlength="50" placeholder="單號" class="{{ $box }} w-40">
                </label>
                <a data-issue-link href="{{ $i?->issue ? route('issues.show', $i->issue->id) : '#' }}" target="_blank"
                   class="text-xs text-indigo-700 hover:underline {{ $i?->issue ? '' : 'hidden' }}">{{ $i?->issue ? $i->issue->title.'（'.$svc->issueStatusLabel($i->issue->status).'）' : '' }}</a>
                <span data-issue-closed class="rounded bg-red-100 px-1.5 text-xs text-red-700 {{ $closed ? '' : 'hidden' }}">內部單已結案</span>
                <span data-issue-trashed class="rounded bg-slate-200 px-1.5 text-xs text-slate-600 {{ $svc->issueTrashed($i) ? '' : 'hidden' }}"
                      title="還原後連結會自動恢復；在單號欄輸入其它單號才會改掉">原關聯的內部單在回收筒</span>
                @if ($canConvert)
                    <a data-issue-convert href="{{ $i ? route('meetings.items.issue', [$m->id, $i->id]) : '#' }}"
                       class="rounded px-1.5 text-xs text-indigo-700 ring-1 ring-indigo-200 hover:bg-indigo-50 {{ $i && ! $i->issue_id ? '' : 'hidden' }}">轉內部單</a>
                @endif
            @endunless
            @if ($from)
                <a href="{{ route('meetings.show', $from->id) }}#item-{{ $i->carried_from_id }}" class="text-xs text-amber-700 hover:underline">延續自 {{ tw($from->start_at, 'm/d') }}</a>
            @endif
            <span data-item-state class="ml-auto text-xs text-slate-400"></span>
        </div>
        @include('meetings._item-history')
    </div>
@else
    <div id="item-{{ $i->id }}" class="rounded-md border border-slate-200 p-3">
        <div class="flex flex-wrap items-start gap-2">
            <span class="w-6 shrink-0 text-right text-sm text-slate-400">{{ $n }}.</span>
            <span class="min-w-0 flex-1 font-medium">{{ $i->topic }}</span>
            @if ($contentVisible)<x-badge scope="meeting_item" :status="$i->status" />@endif
        </div>
        @if ($contentVisible && ($i->discussion || $i->conclusion))
            <div class="mt-2 grid gap-2 pl-8 text-sm md:grid-cols-2">
                <div><div class="text-xs text-slate-500">討論內容</div><div class="whitespace-pre-line">{{ $i->discussion ?: '—' }}</div></div>
                <div><div class="text-xs text-slate-500">結論／解決方法</div><div class="whitespace-pre-line font-medium text-slate-800">{{ $i->conclusion ?: '—' }}</div></div>
            </div>
        @endif
        @include('meetings._item-images', ['editable' => false, 'list' => $images->get($i->id, collect())])
        @if ($contentVisible)
            <div class="mt-2 flex flex-wrap gap-3 pl-8 text-xs text-slate-500">
                @if ($i->owner)<span>負責人：{{ $i->owner->name }}</span>@endif
                @if ($i->due_date)<span>期限：{{ $i->due_date->format('Y-m-d') }}</span>@endif
                @if ($i->issue)<a href="{{ route('issues.show', $i->issue->id) }}" class="text-indigo-700 hover:underline">{{ $i->issue->issue_no }} {{ $i->issue->title }}</a>@endif
                @if ($closed)<span class="rounded bg-red-100 px-1.5 text-red-700">內部單已結案</span>@endif
                @if (! $isClient && $svc->issueTrashed($i))<span class="rounded bg-slate-200 px-1.5 text-slate-600">原關聯的內部單在回收筒</span>@endif
                @if ($from)<a href="{{ route('meetings.show', $from->id) }}" class="text-amber-700 hover:underline">延續自 {{ tw($from->start_at, 'm/d') }}</a>@endif
            </div>
        @endif
        @include('meetings._item-history')
    </div>
@endif
