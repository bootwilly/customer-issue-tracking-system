@extends('layouts.app')
@section('title', $m->title)
@push('head')
    @if ($canEdit)
        @vite(['resources/js/editor/index.js', 'resources/js/meeting/items.js'])
    @endif
@endpush
@php
    $card = 'rounded-lg bg-white p-4 shadow-sm';
    $btn = 'rounded-md px-3 py-1.5 text-sm';
    $box = 'w-full rounded-md border border-slate-300 bg-white px-3 py-1.5 text-sm focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500';
    $wf = app(\App\Services\WorkflowService::class);
    $clientOrigin = $m->isClientOrigin();
    $nextDefault = tw($m->start_at->copy()->addWeek(), 'Y-m-d\TH:i');
@endphp
@section('content')
    <div class="mb-4 flex flex-wrap items-start justify-between gap-3">
        <div>
            <a href="{{ route('meetings.index') }}" class="text-sm text-slate-500 hover:text-indigo-700">&larr; 會議</a>
            <h1 class="text-xl font-semibold">{{ $m->title }}
                <x-badge scope="meeting" :status="$m->status" class="ml-1 align-middle" />
                <span class="ml-1 rounded-full bg-indigo-50 px-2 py-0.5 align-middle text-xs font-normal text-indigo-700">{{ $svc->typeLabel($m->type) }}</span>
                @unless ($isClient)
                    @if ($clientOrigin)
                        <span class="ml-1 rounded bg-sky-100 px-1.5 py-0.5 align-middle text-xs font-normal text-sky-800">客戶發起（客戶看得到）</span>
                    @elseif ($m->customer_visible)
                        <span class="ml-1 rounded bg-emerald-100 px-1.5 py-0.5 align-middle text-xs font-normal text-emerald-800">開放客戶{{ $m->published_at ? '' : '（紀錄未發布）' }}</span>
                    @else
                        <span class="ml-1 rounded bg-slate-200 px-1.5 py-0.5 align-middle text-xs font-normal text-slate-600">僅內部</span>
                    @endif
                @endunless
            </h1>
        </div>
        <div class="flex flex-wrap items-center gap-2 text-sm">
            @foreach ($statusTargets as $t)
                @php $meta = $wf->status('meeting', $t['code']); @endphp
                <form method="POST" action="{{ route('meetings.status', $m->id) }}" @if ($meta->is_terminal) onsubmit="return confirm('確定改為「{{ $t['name'] }}」？')" @endif>
                    @csrf
                    <input type="hidden" name="to" value="{{ $t['code'] }}">
                    <button type="submit" class="{{ $btn }} {{ $meta->is_released ? 'bg-emerald-600 font-medium text-white hover:bg-emerald-700' : 'bg-white text-slate-700 ring-1 ring-slate-300 hover:bg-slate-50' }}">
                        {{ $meta->is_released ? '完成紀錄' : '改為「'.$t['name'].'」' }}
                    </button>
                </form>
            @endforeach
            @if ($canEdit)
                <a href="{{ route('meetings.edit', $m->id) }}" class="{{ $btn }} bg-white text-slate-700 ring-1 ring-slate-300 hover:bg-slate-50">修改資料</a>
            @endif
            <a href="#documents" class="{{ $btn }} text-indigo-700 hover:underline">Markdown 文件</a>
            <a href="{{ route('meetings.ics', $m->id) }}" class="{{ $btn }} text-slate-600 hover:underline">加入行事曆（.ics）</a>
            @if ($canExport)
                <a href="{{ route('meetings.export-one', $m->id) }}" class="{{ $btn }} text-emerald-700 hover:underline">匯出 Excel</a>
            @endif
        </div>
    </div>

    @if ($m->customer_visible && ! $clientOrigin && ! $m->published_at && ! $isClient)
        <div class="mb-4 rounded-md border border-amber-200 bg-amber-50 px-4 py-2 text-sm text-amber-800">
            客戶目前只看得到時間、地點、與會者與問題點；按「完成紀錄」後才看得到討論內容、結論與會議紀錄。
        </div>
    @endif
    @if ($isClient && ! $contentVisible)
        <div class="mb-4 rounded-md border border-sky-200 bg-sky-50 px-4 py-2 text-sm text-sky-800">會議紀錄整理中，完成後會在這裡顯示討論內容與結論。</div>
    @endif

    <div class="grid gap-4 lg:grid-cols-3">
        <div class="space-y-4 lg:col-span-2">
            <section class="{{ $card }}">
                <dl class="grid grid-cols-2 gap-x-4 gap-y-2 text-sm md:grid-cols-3">
                    <div><dt class="text-xs text-slate-500">時間</dt><dd>{{ tw($m->start_at) }}@if ($m->end_at) ～ {{ tw($m->end_at, $m->end_at->isSameDay($m->start_at) ? 'H:i' : 'Y-m-d H:i') }}@endif</dd></div>
                    <div><dt class="text-xs text-slate-500">地點</dt><dd class="break-words">{{ $m->location ?: '—' }}</dd></div>
                    <div><dt class="text-xs text-slate-500">發起人</dt><dd>{{ $m->creator?->name }}</dd></div>
                    <div class="col-span-2 md:col-span-3"><dt class="text-xs text-slate-500">與會者</dt>
                        <dd>@forelse ($attendees as $a)<span class="mr-2 inline-block">{{ $a->label() }}@if ($a->user?->isClient())<span class="ml-0.5 text-xs text-sky-700">（客戶）</span>@elseif (! $a->user_id)<span class="ml-0.5 text-xs text-slate-400">（外部）</span>@endif</span>@empty — @endforelse</dd></div>
                    <div><dt class="text-xs text-slate-500">最後更新</dt><dd>{{ tw($m->updated_at) }} {{ $m->editor?->name }}</dd></div>
                    @if ($m->published_at && ! $clientOrigin)<div><dt class="text-xs text-slate-500">紀錄發布</dt><dd>{{ tw($m->published_at) }}</dd></div>@endif
                    @if ($m->prev && ! $isClient)<div><dt class="text-xs text-slate-500">上次會議</dt><dd><a href="{{ route('meetings.show', $m->prev->id) }}" class="text-indigo-700 hover:underline">{{ tw($m->prev->start_at, 'Y-m-d') }}</a></dd></div>@endif
                </dl>
            </section>

            <section id="items" class="{{ $card }}">
                <div class="mb-2 flex items-center justify-between">
                    <h2 class="text-base font-semibold">討論事項</h2>
                    @if ($canEdit)<span class="text-xs text-slate-400">邊打邊自動儲存</span>@endif
                </div>
                @if ($canEdit)
                    <div data-items data-url="{{ route('meetings.items.store', $m->id) }}"
                         @if ($canPickIssues) data-issues-url="{{ route('meetings.issues.search', $m->id) }}" data-issues-add-url="{{ route('meetings.items.issues', $m->id) }}" data-issue-stages='@json(array_values($issueStages))' @endif
                         class="space-y-2">
                        @foreach ($items as $i)
                            @include('meetings._item', ['i' => $i])
                        @endforeach
                    </div>
                    <template data-item-template>@include('meetings._item', ['i' => null])</template>
                    <div class="mt-2 flex flex-wrap gap-2">
                        <button type="button" data-item-add class="rounded-md px-3 py-1.5 text-sm text-indigo-700 ring-1 ring-indigo-200 hover:bg-indigo-50">＋ 新增討論事項</button>
                        @if ($canPickIssues)
                            <button type="button" data-issue-pick class="rounded-md px-3 py-1.5 text-sm text-indigo-700 ring-1 ring-indigo-200 hover:bg-indigo-50">＋ 從問題管理加入</button>
                        @endif
                    </div>
                @else
                    <div class="space-y-2">
                        @forelse ($items as $i)
                            @include('meetings._item', ['i' => $i, 'n' => $loop->iteration])
                        @empty
                            <p class="text-sm text-slate-400">沒有討論事項。</p>
                        @endforelse
                    </div>
                @endif
            </section>

            <section id="documents" class="{{ $card }}" x-data="{ selected: '' }">
                <h2 class="mb-2 text-base font-semibold">Markdown 文件</h2>
                @if ($canEdit)
                    <form data-document-upload data-max-files="{{ (int) ini_get('max_file_uploads') }}" method="POST" enctype="multipart/form-data" action="{{ route('meetings.documents.store', $m->id) }}" class="mb-4">
                        @csrf
                        <fieldset class="flex flex-wrap items-center gap-3">
                            <label class="text-sm">選取 Markdown 文件
                                <input type="file" name="files[]" accept=".md" multiple required class="block text-sm">
                            </label>
                            @if ($isClient)
                                <input type="hidden" name="internal" value="0">
                            @else
                                <label class="text-sm">可見範圍
                                    <select name="internal" class="{{ $box }}">
                                        <option value="1">僅內部</option>
                                        <option value="0">依會議分享設定</option>
                                    </select>
                                </label>
                            @endif
                            <button type="submit" class="{{ $btn }} bg-indigo-600 text-white hover:bg-indigo-700">上傳文件</button>
                        </fieldset>
                        <p data-upload-status role="status" class="mt-1 text-sm"></p>
                    </form>
                @endif
                <div data-document-list>
                    @include('meetings._document-list')
                </div>
                <template x-if="selected">
                    <iframe title="Markdown 文件預覽" class="mt-3 h-96 w-full rounded border" :src="selected"></iframe>
                </template>
            </section>

            <section id="notes" class="{{ $card }}">
                <h2 class="mb-2 text-base font-semibold">補充紀錄</h2>
                @if ($canEdit)
                    <form method="POST" action="{{ route('meetings.notes', $m->id) }}" class="space-y-3">
                        @csrf
                        <input type="hidden" name="notes_rev" value="{{ $m->notes_rev }}">
                        <x-editor name="notes_html" :value="old('notes_html', $m->notes_html)" :context="$svc->notesContext($m->id, false)" />
                        @unless ($isClient)
                            <div>
                                <h3 class="mb-1 text-sm font-semibold text-slate-700">內部備註 <span class="text-xs font-normal text-slate-500">（客戶永遠看不到）</span></h3>
                                <x-editor name="internal_html" :value="old('internal_html', $m->internal_html)" :context="$svc->notesContext($m->id, true)" />
                            </div>
                        @endunless
                        <button type="submit" class="{{ $btn }} bg-indigo-600 font-medium text-white hover:bg-indigo-700">儲存紀錄</button>
                    </form>
                @else
                    @if ($contentVisible && $m->notes_html)
                        <div class="prose max-w-none text-sm">{!! $m->notes_html !!}</div>
                    @else
                        <p class="text-sm text-slate-400">{{ $contentVisible ? '沒有補充紀錄。' : '紀錄發布後顯示。' }}</p>
                    @endif
                    @if (! $isClient && $m->internal_html)
                        <h3 class="mb-1 mt-4 text-sm font-semibold text-slate-700">內部備註 <span class="text-xs font-normal text-slate-500">（客戶看不到）</span></h3>
                        <div class="prose max-w-none rounded bg-slate-50 p-3 text-sm">{!! $m->internal_html !!}</div>
                    @endif
                @endif
            </section>

            @include('meetings._comments')
        </div>

        <aside class="space-y-4">
            @if ($canShare)
                <section class="{{ $card }}">
                    <h2 class="mb-2 text-base font-semibold">開放給客戶</h2>
                    <form method="POST" action="{{ route('meetings.share', $m->id) }}"
                          @if ($m->customer_visible) onsubmit="return confirm('取消開放後，客戶看不到這場會議（含他們的留言）。確定？')" @endif>
                        @csrf
                        <input type="hidden" name="visible" value="{{ $m->customer_visible ? 0 : 1 }}">
                        <p class="mb-2 text-xs text-slate-500">{{ $m->customer_visible ? '目前專案內的客戶看得到這場會議。' : '目前只有內部人員看得到。' }}</p>
                        <button type="submit" class="{{ $btn }} {{ $m->customer_visible ? 'bg-white text-slate-700 ring-1 ring-slate-300 hover:bg-slate-50' : 'bg-emerald-600 font-medium text-white hover:bg-emerald-700' }}">
                            {{ $m->customer_visible ? '取消開放' : '開放給客戶' }}
                        </button>
                    </form>
                </section>
            @endif

            @if ($canCreate)
                <section class="{{ $card }}">
                    <h2 class="mb-2 text-base font-semibold">安排下次會議</h2>
                    <form method="POST" action="{{ route('meetings.next', $m->id) }}" class="space-y-2">
                        @csrf
                        <input type="datetime-local" name="start_at" value="{{ old('start_at', $nextDefault) }}" required class="{{ $box }}">
                        <p class="text-xs text-slate-500">帶入主題、地點、與會者，以及還在追蹤中的討論事項。</p>
                        <button type="submit" class="{{ $btn }} bg-indigo-600 font-medium text-white hover:bg-indigo-700">建立下次會議</button>
                    </form>
                </section>
            @endif

            @if ($canDelete)
                <section class="{{ $card }}">
                    <form method="POST" action="{{ route('meetings.destroy', $m->id) }}" onsubmit="return confirm('移到回收筒？（專案管理 → 回收筒可還原）')">
                        @csrf @method('DELETE')
                        <button type="submit" class="text-sm text-red-600 hover:underline">刪除會議</button>
                    </form>
                </section>
            @endif
        </aside>
    </div>
@endsection
