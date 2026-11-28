@extends('layouts.app')
@section('title', $issue->issue_no)
@push('head')
    @vite('resources/js/editor/index.js')
@endpush
@php
    $wf = app(\App\Services\WorkflowService::class);
    $originLabels = $wf->lookupMapAll('issue_origin');
    $input = 'w-full rounded-md border border-slate-300 bg-white px-2 py-1.5 text-sm focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500';
    $btn = 'rounded-md px-3 py-1.5 text-sm font-medium text-white';
    $card = 'rounded-lg bg-white p-4 shadow-sm';
    $editable = $ab['editable'];
    $locked = $issue->content_locked_at !== null;
    $can = fn ($f) => in_array($f, $editable, true);
    $fieldLabels = [
        'assignee_dev_id' => '指派 DEV', 'assignee_test_id' => '指派 Test', 'planned_due' => '預計完工日', 'package_id' => '更新包',
        'test_scheduled_at' => '排程測試日', 'release_planned_at' => '預計更新正式環境日', 'close_reason' => '結案原因', 'cancel_reason' => '原因', 'client_note' => '給客戶的說明',
    ];
@endphp
@section('content')
    <div class="mb-4 flex flex-wrap items-center gap-3">
        <a href="{{ route('pm.index') }}" class="text-sm text-slate-500 hover:text-indigo-700">&larr; 問題管理</a>
        <h1 class="text-xl font-semibold">{{ $issue->issue_no }}　{{ $issue->title }}</h1>
        <x-badge :status="$issue->status" scope="issue" />
        @if ($issue->origin !== 'client')<span class="rounded bg-slate-200 px-2 py-0.5 text-xs text-slate-600">內部</span>@endif
        @foreach ($tags as $tg)<span class="rounded bg-violet-100 px-2 py-0.5 text-xs text-violet-800">{{ $tagLabels[$tg] ?? $tg }}</span>@endforeach
        @if ($deps['blockers'])<a href="#deps" class="rounded bg-orange-100 px-2 py-0.5 text-xs text-orange-800">需等待 {{ implode('、', $deps['blockers']) }}</a>@endif
        <span class="ml-auto">@include('collab._watch', ['action' => route('collab.issues.watch', $issue->id), 'watching' => $watching, 'watchers' => $watchers])</span>
        @if ($canDelete)
            <form method="POST" action="{{ route('issues.destroy', $issue->id) }}" onsubmit="return confirm('將 {{ $issue->issue_no }} 移到回收筒？\n專案管理員可以從回收筒還原。')">
                @csrf @method('DELETE')
                <button type="submit" class="rounded-md border border-red-300 px-3 py-1 text-sm text-red-700 hover:bg-red-50">刪除</button>
            </form>
        @endif
    </div>

    @if ($issue->close_target || $closeTasks->isNotEmpty())
        <section id="close-tasks" class="{{ $card }} mb-4 space-y-2">
            <h2 class="font-semibold">{{ $issue->close_target ? '結案收尾中' : '收尾紀錄' }}</h2>
            @if ($issue->close_target)<p>{{ $closeLabel }} · {{ $issue->close_reason }}</p>@endif
            @if ($issue->closeMeetingItem)
                <a class="text-indigo-700 underline" href="{{ route('meetings.show', $issue->closeMeetingItem->meeting_id) }}#item-{{ $issue->close_meeting_item_id }}">來源會議事項</a>
            @endif
            @foreach ($closeTasks as $task)
                <div class="border-t pt-2 text-sm">
                    第 {{ $task->round }} 輪 · {{ $closeRoleLabels[$task->role] }} · {{ $task->user?->name ?? '上線負責人' }} · {{ $closeTaskLabels[$task->status] }}
                    @if ($task->done_at)<p>{{ $task->doneBy?->name }} · {{ tw($task->done_at) }} · {{ $task->note }}</p>@endif
                    @if (in_array($task->id, $ownCloseTasks, true))
                        <form method="POST" action="{{ route('issues.close-tasks.finish', [$issue->id, $task->id]) }}">@csrf
                            <label>收尾說明<textarea name="note" rows="2" maxlength="1000" required class="{{ $input }}"></textarea></label>
                            <button class="{{ $btn }} bg-indigo-600">確認收尾</button>
                        </form>
                    @endif
                </div>
            @endforeach
            @if ($canWithdraw)
                <form method="POST" action="{{ route('issues.close.withdraw', $issue->id) }}" onsubmit="return confirm('撤回結案並恢復原狀態？')">@csrf
                    <button class="{{ $btn }} bg-amber-700">撤回結案</button>
                </form>
            @endif
        </section>
    @endif
    <section class="{{ $card }} mb-4">
        <dl class="grid grid-cols-2 gap-x-4 gap-y-2 text-sm md:grid-cols-4">
            <div><dt class="text-xs text-slate-500">來源</dt><dd>{{ $originLabels[$issue->origin] ?? $issue->origin }}</dd></div>
            <div><dt class="text-xs text-slate-500">階段</dt><dd>{{ $issue->stage }}</dd></div>
            <div><dt class="text-xs text-slate-500">嚴重度</dt><dd>@include('pm._severity', ['n' => $issue->severity])</dd></div>
            <div><dt class="text-xs text-slate-500">難易度</dt><dd>{{ $issue->difficulty ?: '-' }}</dd></div>
            <div><dt class="text-xs text-slate-500">處理類型</dt><dd>{{ $issue->process_type ? $wf->lookupLabel('process_type', $issue->process_type) : '-' }}</dd></div>
            <div><dt class="text-xs text-slate-500">MES 頁面</dt><dd>{{ $issue->mesPage?->name ?: '-' }}</dd></div>
            <div><dt class="text-xs text-slate-500">DEV</dt><dd>{{ $issue->dev?->name ?: '-' }}</dd></div>
            <div><dt class="text-xs text-slate-500">Test</dt><dd>{{ $issue->tester?->name ?: '-' }}</dd></div>
            <div><dt class="text-xs text-slate-500">原預計完工</dt><dd>{{ $issue->planned_due?->format('Y-m-d') ?: '-' }}</dd></div>
            <div><dt class="text-xs text-slate-500">目前預計完工</dt><dd>{{ $issue->current_due?->format('Y-m-d') ?: '-' }}</dd></div>
            <div><dt class="text-xs text-slate-500">預計開始</dt><dd>{{ $issue->planned_start?->format('Y-m-d') ?: '-' }}</dd></div>
            <div><dt class="text-xs text-slate-500">測試日期</dt><dd>{{ $issue->test_scheduled_at?->format('Y-m-d') ?: '-' }}</dd></div>
            <div><dt class="text-xs text-slate-500">預計更新日</dt><dd>{{ $issue->release_planned_at?->format('Y-m-d') ?: '-' }}</dd></div>
            <div><dt class="text-xs text-slate-500">更新包</dt><dd>{{ $issue->package?->name ?: '-' }}</dd></div>
            <div><dt class="text-xs text-slate-500">退回次數</dt><dd>{{ $issue->rework_count }}</dd></div>
            <div><dt class="text-xs text-slate-500">建立</dt><dd>{{ tw($issue->created_at) }} {{ $issue->creator?->name }}</dd></div>
            <div class="col-span-2 md:col-span-4"><dt class="text-xs text-slate-500">客戶單</dt>
                <dd>@forelse ($issue->tickets as $t)<a href="{{ route('pm.tickets.show', $t->id) }}" class="mr-3 text-indigo-700 hover:underline">{{ $t->ticket_no }}</a>@empty - @endforelse</dd></div>
            @if ($issue->dev_note)<div class="col-span-2 md:col-span-4"><dt class="text-xs text-slate-500">DEV 備註</dt><dd class="whitespace-pre-line">{{ $issue->dev_note }}</dd></div>@endif
            @if ($issue->closed_at)
                <div class="col-span-2"><dt>結案原因</dt><dd class="whitespace-pre-line">{{ $issue->close_reason }}</dd></div>
                <div class="col-span-2"><dt>結案方式／時間</dt><dd>{{ $closeLabel }} · {{ tw($issue->closed_at) }}</dd></div>
            @endif
            @if ($issue->cancel_reason)<div class="col-span-2 md:col-span-4"><dt class="text-xs text-slate-500">取消／不處理原因</dt><dd>{{ $issue->cancel_reason }}</dd></div>@endif
        </dl>
    </section>

    <div class="grid gap-4 lg:grid-cols-3">
        <div class="space-y-4 lg:col-span-2">
            <section class="{{ $card }}">
                <h2 class="mb-2 text-base font-semibold">內容</h2>
                <div class="prose max-w-none text-sm">@richtext($issue->content_html)</div>
            </section>

            <section class="{{ $card }}">
                <h2 class="mb-2 text-base font-semibold">補充說明</h2>
                <div class="space-y-3">
                    @forelse ($issue->addenda as $a)
                        <div class="rounded border border-slate-100 p-3 text-sm">
                            <div class="mb-1 text-xs text-slate-500"><b class="text-slate-700">{{ $a->author?->name }}</b> {{ tw($a->created_at) }}</div>
                            <div class="prose max-w-none">@richtext($a->content_html)</div>
                        </div>
                    @empty
                        <p class="text-sm text-slate-400">尚無補充說明</p>
                    @endforelse
                </div>
                @if ($ab['addendum'])
                    <form method="POST" action="{{ route('issues.addendum', $issue->id) }}" class="mt-3 space-y-2">@csrf
                        <input type="hidden" name="draft" value="issue:addendum:{{ $issue->id }}">
                        <x-editor name="content_html" context="issue:addendum:{{ $issue->id }}" />
                        <button class="{{ $btn }} bg-indigo-600 hover:bg-indigo-700">新增補充說明</button>
                    </form>
                @endif
            </section>

            <section class="{{ $card }}">
                <h2 class="mb-1 text-base font-semibold">客戶補充</h2>
                <p class="mb-2 text-xs text-slate-500">來自連結客戶單的留言（唯讀）</p>
                <div class="space-y-3">
                    @forelse ($clientComments as $c)
                        <div class="rounded border border-slate-100 bg-slate-50 p-3 text-sm">
                            <div class="mb-1 text-xs text-slate-500">
                                <b class="text-slate-700">{{ $c->author?->name }}</b>
                                {{ $issue->tickets->firstWhere('id', $c->ticket_id)?->ticket_no }}
                                {{ $wf->lookupLabel('comment_kind', $c->kind) }} {{ tw($c->created_at) }}
                            </div>
                            <div class="prose max-w-none">@richtext($c->content_html)</div>
                        </div>
                    @empty
                        <p class="text-sm text-slate-400">沒有客戶留言</p>
                    @endforelse
                </div>
            </section>

            <section class="{{ $card }}">
                <h2 class="mb-2 text-base font-semibold">內部留言</h2>
                <div class="space-y-3">
                    @forelse ($issue->comments as $c)
                        <div class="rounded border border-slate-100 p-3 text-sm">
                            <div class="mb-1 text-xs text-slate-500"><b class="text-slate-700">{{ $c->author?->name }}</b> {{ tw($c->created_at) }}</div>
                            <div class="prose max-w-none">@richtext($c->content_html)</div>
                        </div>
                    @empty
                        <p class="text-sm text-slate-400">尚無留言</p>
                    @endforelse
                </div>
                @if ($ab['comment'])
                    <form method="POST" action="{{ route('issues.comment', $issue->id) }}" class="mt-3 space-y-2">@csrf
                        <input type="hidden" name="draft" value="issue:comment:{{ $issue->id }}">
                        <x-editor name="content_html" context="issue:comment:{{ $issue->id }}" />
                        <button class="{{ $btn }} bg-indigo-600 hover:bg-indigo-700">送出留言</button>
                    </form>
                @endif
            </section>
        </div>

        <aside class="space-y-4">
            {{-- 狀態變更 --}}
            @unless ($issue->close_target)
            <section class="{{ $card }}">
                <h2 class="mb-2 text-base font-semibold">狀態變更</h2>
                @forelse ($targets as $t)
                    <details class="mb-2 rounded border border-slate-200" @if (old('to') === $t['code']) open @endif>
                        <summary class="cursor-pointer px-3 py-2 text-sm font-medium">{{ $t['name'] }}</summary>
                        <form method="POST" action="{{ route('issues.transition', $issue->id) }}" class="space-y-2 border-t border-slate-100 p-3">@csrf
                            <input type="hidden" name="to" value="{{ $t['code'] }}">
                            @foreach ($t['fields'] as $f)
                                @continue($f === 'client_note' && ! $linked && in_array('close_reason', $t['fields'], true))
                                <div>
                                    <label class="text-xs text-slate-500">{{ $fieldLabels[$f] ?? $f }}</label>
                                    @switch($f)
                                        @case('assignee_dev_id')
                                            <select name="{{ $f }}" required class="{{ $input }}"><option value="">請選擇</option>
                                                @foreach ($devUsers as $u)<option value="{{ $u->id }}">{{ $u->name }}</option>@endforeach</select>
                                            @break
                                        @case('assignee_test_id')
                                            <select name="{{ $f }}" required class="{{ $input }}"><option value="">請選擇</option>
                                                @foreach ($testUsers as $u)<option value="{{ $u->id }}">{{ $u->name }}</option>@endforeach</select>
                                            @break
                                        @case('package_id')
                                            <select name="package_id" class="{{ $input }}"><option value="">請選擇</option>
                                                @foreach ($packages as $p)<option value="{{ $p->id }}" @selected($issue->package_id === $p->id)>{{ $p->name }}</option>@endforeach</select>
                                            @unless ($packages->contains(fn ($p) => $p->released_at || $p->has_released_issue))
                                                <input type="text" name="new_package_name" maxlength="150" placeholder="或新增更新包名稱" class="{{ $input }} mt-1">
                                            @endunless
                                            @break
                                        @case('planned_due')
                                        @case('test_scheduled_at')
                                        @case('release_planned_at')
                                            <input type="date" name="{{ $f }}" required class="{{ $input }}">
                                            @break
                                        @case('close_reason')
                                            <textarea name="close_reason" rows="2" maxlength="1000" required class="{{ $input }}">{{ old('close_reason') }}</textarea>
                                            <p class="text-xs text-slate-600">{{ $closePreview }}</p>
                                            @break
                                        @case('client_note')
                                            <textarea name="client_note" rows="2" maxlength="500" class="{{ $input }}" @if ($linked) required @endif></textarea>
                                            @if ($linked)<p class="mt-0.5 text-xs text-amber-700">此單已連結客戶單，請填寫給客戶看的說明。</p>@endif
                                            @break
                                        @default
                                            <textarea name="{{ $f }}" rows="2" maxlength="500" required class="{{ $input }}"></textarea>
                                    @endswitch
                                </div>
                            @endforeach
                            <button class="{{ $btn }} bg-indigo-600 hover:bg-indigo-700">確認：{{ $t['name'] }}</button>
                        </form>
                    </details>
                @empty
                    <p class="text-sm text-slate-400">目前沒有可執行的狀態變更</p>
                @endforelse
            </section>
            @endunless

            {{-- 預計完工 --}}
            @if ($ab['set_due'])
                <section class="{{ $card }}">
                    <h2 class="mb-2 text-base font-semibold">設定預計完工日</h2>
                    <form method="POST" action="{{ route('issues.due', $issue->id) }}" class="flex gap-2">@csrf
                        <input type="date" name="date" required class="{{ $input }}">
                        <button class="{{ $btn }} shrink-0 bg-indigo-600 hover:bg-indigo-700">設定</button>
                    </form>
                </section>
            @endif
            @if ($ab['request_due'])
                <section class="{{ $card }}">
                    <h2 class="mb-2 text-base font-semibold">申請延期</h2>
                    <form method="POST" action="{{ route('issues.due_request', $issue->id) }}" class="space-y-2">@csrf
                        <input type="date" name="new_due" required class="{{ $input }}">
                        <textarea name="reason" rows="2" required maxlength="500" placeholder="延期原因" class="{{ $input }}"></textarea>
                        <button class="{{ $btn }} bg-amber-600 hover:bg-amber-700">送出申請</button>
                    </form>
                </section>
            @endif
            @if ($issue->dueRequests->isNotEmpty())
                <section class="{{ $card }}">
                    <h2 class="mb-2 text-base font-semibold">延期申請紀錄</h2>
                    <ul class="space-y-1 text-xs text-slate-600">
                        @foreach ($issue->dueRequests as $r)
                            <li>{{ tw($r->created_at) }} {{ $r->requester?->name }}：{{ $r->new_due->format('Y-m-d') }}（{{ $r->reason }}）
                                <b>{{ ['pending' => '待審核', 'approved' => '已核准', 'rejected' => '已駁回'][$r->status] ?? $r->status }}</b></li>
                        @endforeach
                    </ul>
                </section>
            @endif

            {{-- 欄位編輯 --}}
            @if ($editable)
                <section class="{{ $card }}">
                    <h2 class="mb-2 text-base font-semibold">編輯欄位</h2>
                    @if ($locked && ($can('title') || $can('content_html')))<p class="mb-2 text-xs text-slate-500">已拋單，標題與內容已鎖定，請用補充說明。</p>@endif
                    <form method="POST" action="{{ route('issues.fields', $issue->id) }}" class="space-y-2">@csrf
                        <input type="hidden" name="draft" value="issue:edit:{{ $issue->id }}">
                        @if ($can('title') && ! $locked)
                            <div><label class="text-xs text-slate-500">標題</label><input name="title" maxlength="200" value="{{ old('title', $issue->title) }}" class="{{ $input }}"></div>
                        @endif
                        @if ($can('severity'))
                            <div><label class="text-xs text-slate-500">嚴重度</label>
                                <select name="severity" class="{{ $input }}"><option value="">-</option>@for ($k = 1; $k <= 5; $k++)<option value="{{ $k }}" @selected((int) old('severity', $issue->severity) === $k)>{{ $k }}</option>@endfor</select></div>
                        @endif
                        @if ($can('difficulty'))
                            <div><label class="text-xs text-slate-500">難易度</label>
                                <select name="difficulty" class="{{ $input }}"><option value="">-</option>@for ($k = 1; $k <= 5; $k++)<option value="{{ $k }}" @selected((int) old('difficulty', $issue->difficulty) === $k)>{{ $k }}</option>@endfor</select></div>
                        @endif
                        @if ($can('process_type'))
                            <div><label class="text-xs text-slate-500">處理類型</label>
                                <select name="process_type" class="{{ $input }}"><option value="">-</option>@foreach ($processTypes as $p)<option value="{{ $p->code }}" @selected(old('process_type', $issue->process_type) === $p->code)>{{ $p->name }}</option>@endforeach
                                    @if ($issue->process_type && ! $processTypes->contains('code', $issue->process_type))<option value="{{ $issue->process_type }}" selected>{{ $wf->lookupLabel('process_type', $issue->process_type) }}（已停用）</option>@endif</select></div>
                        @endif
                        @if ($can('mes_page_id'))
                            <div><label class="text-xs text-slate-500">MES 頁面</label>
                                <select name="mes_page_id" class="{{ $input }}"><option value="">-</option>@foreach ($mesPages as $m)<option value="{{ $m->id }}" @selected((int) old('mes_page_id', $issue->mes_page_id) === $m->id)>{{ $m->name }}</option>@endforeach</select></div>
                        @endif
                        @if ($can('planned_start'))
                            <div><label class="text-xs text-slate-500">預計開始日</label><input type="date" name="planned_start" value="{{ old('planned_start', $issue->planned_start?->format('Y-m-d')) }}" class="{{ $input }}"></div>
                        @endif
                        @if ($can('dev_note'))
                            <div><label class="text-xs text-slate-500">DEV 備註</label><textarea name="dev_note" rows="3" maxlength="5000" class="{{ $input }}">{{ old('dev_note', $issue->dev_note) }}</textarea></div>
                        @endif
                        @if ($can('content_html') && ! $locked)
                            <div><label class="text-xs text-slate-500">內容</label>
                                <x-editor name="content_html" :value="old('content_html', $issue->content_html)" context="issue:edit:{{ $issue->id }}" /></div>
                        @endif
                        <button class="{{ $btn }} bg-indigo-600 hover:bg-indigo-700">儲存</button>
                    </form>
                </section>
            @endif

            {{-- 標籤 --}}
            @if ($canTag || $tags)
                <section class="{{ $card }}">
                    <h2 class="mb-2 text-base font-semibold">標籤</h2>
                    @if ($canTag && ($tagOptions || $tags))
                        <form method="POST" action="{{ route('collab.issues.tags', $issue->id) }}" class="space-y-2">@csrf
                            <div class="flex flex-wrap gap-x-3 gap-y-1">
                                @foreach ($tagLabels as $code => $name)
                                    @if (isset($tagOptions[$code]) || in_array($code, $tags, true))
                                        <label class="flex items-center gap-1 text-sm"><input type="checkbox" name="tags[]" value="{{ $code }}" @checked(in_array($code, $tags, true)) class="rounded border-slate-300"> {{ $name }}@unless (isset($tagOptions[$code]))<span class="text-xs text-slate-400">（已停用）</span>@endunless</label>
                                    @endif
                                @endforeach
                            </div>
                            <button class="{{ $btn }} bg-violet-600 hover:bg-violet-700">儲存標籤</button>
                        </form>
                    @elseif ($canTag)
                        <p class="text-sm text-slate-400">還沒有標籤。系統管理員可在「選項管理 → 內部單標籤」新增。</p>
                    @else
                        <div class="flex flex-wrap gap-1">@foreach ($tags as $tg)<span class="rounded bg-violet-100 px-2 py-0.5 text-xs text-violet-800">{{ $tagLabels[$tg] ?? $tg }}</span>@endforeach</div>
                    @endif
                    <p class="mt-1 text-xs text-slate-400">標籤只有內部人員看得到。</p>
                </section>
            @endif

            {{-- 相依關係 --}}
            <section class="{{ $card }}" id="deps">
                <h2 class="mb-2 text-base font-semibold">相依關係</h2>
                @if ($deps['blockers'])
                    <p class="mb-2 rounded bg-orange-50 p-2 text-xs text-orange-800">需等待 {{ implode('、', $deps['blockers']) }} 上線或排除後，才能從 PM 階段派給 DEV。</p>
                @endif
                @forelse ($deps['upstream'] as $d)
                    <div class="mb-1 flex items-start gap-2 text-sm">
                        <span class="shrink-0 rounded bg-slate-100 px-1.5 text-xs text-slate-600">{{ $depKinds[$d->kind] ?? $d->kind }}</span>
                        <a href="{{ route('issues.show', $d->depends_on_id) }}" class="min-w-0 flex-1 text-indigo-700 hover:underline">{{ $d->dependsOn?->issue_no }} {{ $d->dependsOn?->title }}</a>
                        @if ($d->dependsOn)<x-badge scope="issue" :status="$d->dependsOn->status" />@endif
                        @if ($canDeps)
                            <form method="POST" action="{{ route('collab.deps.destroy', [$issue->id, $d->id]) }}" onsubmit="return confirm('移除這個相依關係？')">@csrf @method('DELETE')<button class="text-xs text-red-600 hover:underline" title="移除">✕</button></form>
                        @endif
                    </div>
                @empty
                    <p class="text-sm text-slate-400">這張單不需等待其他單。</p>
                @endforelse
                @if ($deps['downstream']->isNotEmpty())
                    <div class="mt-2 text-xs text-slate-500">等待這張單的：</div>
                    @foreach ($deps['downstream'] as $d)
                        <div class="mb-1 flex items-start gap-2 text-sm">
                            <span class="shrink-0 rounded bg-slate-100 px-1.5 text-xs text-slate-600">{{ $depKinds[$d->kind] ?? $d->kind }}</span>
                            <a href="{{ route('issues.show', $d->issue_id) }}" class="min-w-0 flex-1 text-indigo-700 hover:underline">{{ $d->issue?->issue_no }} {{ $d->issue?->title }}</a>
                            @if ($d->issue)<x-badge scope="issue" :status="$d->issue->status" />@endif
                            @if ($canDeps)
                                <form method="POST" action="{{ route('collab.deps.destroy', [$issue->id, $d->id]) }}" onsubmit="return confirm('移除這個相依關係？')">@csrf @method('DELETE')<button class="text-xs text-red-600 hover:underline" title="移除">✕</button></form>
                            @endif
                        </div>
                    @endforeach
                @endif
                @if ($canDeps)
                    <form method="POST" action="{{ route('collab.deps.store', $issue->id) }}" class="mt-2 space-y-1 border-t border-slate-100 pt-2">@csrf
                        <div class="flex gap-1">
                            <select name="kind" class="{{ $input }} w-auto">
                                @foreach ($depKinds as $code => $name)<option value="{{ $code }}">{{ $name }}</option>@endforeach
                            </select>
                            <input type="text" name="issue_no" required maxlength="50" placeholder="內部單單號" value="{{ old('issue_no') }}" class="{{ $input }}">
                        </div>
                        <button class="{{ $btn }} w-full bg-slate-700 hover:bg-slate-800">新增：這張單 → 等待／相關</button>
                    </form>
                @endif
            </section>

            <section class="{{ $card }}">
                <h2 class="mb-2 text-base font-semibold">會議紀錄</h2>
                @forelse ($meetingThreads as $th)
                    @php $last = $th['latest']; $n = $th['items']->count(); @endphp
                    <details class="mb-2 rounded border border-slate-100 p-2 text-sm" @if ($loop->first) open @endif>
                        <summary class="cursor-pointer">
                            <a href="{{ route('meetings.show', $last->meeting_id) }}#item-{{ $last->id }}" class="font-medium text-indigo-700 hover:underline">{{ $last->meeting?->title }}</a>
                            <span class="text-xs text-slate-500">{{ $n > 1 ? $n.' 場：'.tw($th['items']->first()->meeting?->start_at, 'm/d').' → '.tw($last->meeting?->start_at, 'm/d') : tw($last->meeting?->start_at, 'Y-m-d') }}</span>
                            <x-badge scope="meeting_item" :status="$last->status" class="ml-1" />
                            @if ($th['conclusion'])<div class="mt-1 whitespace-pre-line text-slate-700">{{ $th['conclusion'] }}</div>@endif
                        </summary>
                        <ol class="mt-2 space-y-2 border-l border-slate-200 pl-3">
                            @foreach ($th['items'] as $mi)
                                <li>
                                    <a href="{{ route('meetings.show', $mi->meeting_id) }}#item-{{ $mi->id }}" class="text-xs font-medium text-indigo-700 hover:underline">{{ tw($mi->meeting?->start_at, 'Y-m-d') }} {{ $mi->meeting?->title }}</a>
                                    <x-badge scope="meeting_item" :status="$mi->status" class="ml-1" />
                                    @if ($mi->discussion)<div class="mt-1 whitespace-pre-line text-xs text-slate-600">{{ $mi->discussion }}</div>@endif
                                    @if ($mi->conclusion)<div class="mt-1 whitespace-pre-line text-slate-800">{{ $mi->conclusion }}</div>@endif
                                    @if ($mi->owner || $mi->due_date)<div class="mt-1 text-xs text-slate-500">{{ $mi->owner?->name }} {{ $mi->due_date?->format('Y-m-d') }}</div>@endif
                                </li>
                            @endforeach
                        </ol>
                    </details>
                @empty
                    <p class="text-sm text-slate-400">還沒有在會議中討論過。</p>
                @endforelse
                @if ($meetingTargets->isNotEmpty())
                    <form method="POST" action="{{ route('meetings.add-issue') }}" class="mt-2 flex gap-1">@csrf
                        <input type="hidden" name="issue_id" value="{{ $issue->id }}">
                        <select name="meeting_id" class="{{ $input }}">
                            @foreach ($meetingTargets as $mt)
                                <option value="{{ $mt->id }}">{{ tw($mt->start_at, 'm-d H:i') }} {{ $mt->title }}</option>
                            @endforeach
                        </select>
                        <button class="{{ $btn }} shrink-0 bg-indigo-600 hover:bg-indigo-700">排入會議</button>
                    </form>
                @endif
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
