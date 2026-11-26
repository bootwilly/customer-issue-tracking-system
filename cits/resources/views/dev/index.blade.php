@extends('layouts.app')
@section('title', '開發管理')
@section('content')
    @php
        $tabs = ['active' => '待開發與進行中', 'all' => '全部', 'schedule' => '排程清單'];
        $keep = ['f' => 1, 'mine' => $mine ? 1 : null, 'status' => $status, 'q' => $q ?: null, 'overdue' => $overdue ? 1 : null];
    @endphp
    <div class="mb-4 flex flex-wrap items-center justify-between gap-2">
        <h1 class="text-xl font-semibold">開發管理</h1>
        @if ($canManage)
            <a href="{{ route('dev.issues.create') }}" class="rounded-md bg-indigo-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-indigo-700">新增開發問題</a>
        @endif
    </div>

    <div class="mb-4 flex flex-wrap gap-1 border-b border-slate-200">
        @foreach ($tabs as $k => $label)
            <a href="{{ route('dev.index', array_filter(['tab' => $k] + $keep, fn ($v) => $v !== null)) }}"
               class="-mb-px border-b-2 px-4 py-2 text-sm {{ $tab === $k ? 'border-indigo-600 font-medium text-indigo-700' : 'border-transparent text-slate-600 hover:text-slate-900' }}">{{ $label }}</a>
        @endforeach
    </div>

    @include('collab._saved_queries', ['scope' => 'dev'])

    <form method="GET" action="{{ route('dev.index') }}" class="mb-4 flex flex-wrap items-end gap-3 rounded-lg bg-white p-3 shadow-sm">
        <input type="hidden" name="tab" value="{{ $tab }}">
        <input type="hidden" name="f" value="1">
        <label class="flex items-center gap-1.5 text-sm"><input type="checkbox" name="mine" value="1" @checked($mine) class="rounded border-slate-300"> 我負責的</label>
        @if ($tab !== 'schedule')
            <label class="text-sm">狀態
                <select name="status" class="ml-1 rounded-md border border-slate-300 py-1 text-sm">
                    <option value="">全部</option>
                    @foreach ($statusOptions as $code => $name)
                        <option value="{{ $code }}" @selected($status === $code)>{{ $name }}</option>
                    @endforeach
                </select>
            </label>
            <label class="text-sm">關鍵字
                <input type="text" name="q" value="{{ $q }}" placeholder="單號或標題" class="ml-1 rounded-md border border-slate-300 px-2 py-1 text-sm">
            </label>
            <label class="flex items-center gap-1.5 text-sm"><input type="checkbox" name="overdue" value="1" @checked($overdue) class="rounded border-slate-300"> 僅逾期</label>
        @endif
        <button type="submit" class="rounded-md bg-slate-800 px-3 py-1.5 text-sm text-white hover:bg-slate-700">篩選</button>
        @if ($canExport ?? false)
            <a href="{{ route('dev.export', request()->query()) }}" class="rounded-md border border-slate-300 bg-white px-3 py-1.5 text-sm text-slate-700 hover:bg-slate-50">匯出 Excel</a>
        @endif
    </form>

    @if ($tab === 'schedule')
        @php $any = collect($groups)->flatten(1)->isNotEmpty(); @endphp
        @if (! $any)
            <x-empty>目前沒有已排定完工日的問題。</x-empty>
        @endif
        @foreach ($groups as $label => $list)
            @continue($list->isEmpty())
            <h2 class="mb-2 mt-5 text-sm font-semibold {{ $label === '已逾期' ? 'text-red-600' : 'text-slate-700' }}">{{ $label }}（{{ $list->count() }}）</h2>
            <div class="overflow-x-auto rounded-lg bg-white shadow-sm">
                <table class="min-w-full divide-y divide-slate-200 text-sm">
                    <tbody class="divide-y divide-slate-100">
                        @foreach ($list as $i)
                            <tr class="hover:bg-slate-50">
                                <td class="whitespace-nowrap px-3 py-2 {{ $label === '已逾期' ? 'font-medium text-red-600' : '' }}">{{ $i->current_due->toDateString() }}</td>
                                <td class="whitespace-nowrap px-3 py-2"><a href="{{ url('/issues/'.$i->id) }}" class="font-medium text-indigo-600 hover:underline">{{ $i->issue_no }}</a></td>
                                <td class="px-3 py-2">{{ $i->title }}</td>
                                <td class="whitespace-nowrap px-3 py-2"><x-badge :status="$i->status" scope="issue" /></td>
                                <td class="whitespace-nowrap px-3 py-2 text-slate-500">{{ $i->dev?->name ?? '-' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endforeach
    @else
        @include('dev._table', ['issues' => $issues, 'mode' => 'dev', 'dueIds' => $dueIds])
    @endif

    <section class="mt-8 rounded-lg bg-white p-4 shadow-sm">
        <h2 class="mb-3 text-sm font-semibold text-slate-700">更新包</h2>
        @if ($canManage)
            <form method="POST" action="{{ route('dev.packages.store') }}" class="mb-3 flex flex-wrap gap-2">
                @csrf
                <input type="text" name="name" required maxlength="150" placeholder="更新包名稱，例如 v1.0.2" class="rounded-md border border-slate-300 px-2 py-1 text-sm">
                <button type="submit" class="rounded-md bg-indigo-600 px-3 py-1 text-sm text-white hover:bg-indigo-700">新增更新包</button>
            </form>
        @endif
        @forelse ($packages as $p)
            @php $inPkg = $packageIssues->get($p->id, collect()); @endphp
            <div class="border-t border-slate-100 py-2 text-sm">
                <div class="flex justify-between">
                    <span class="font-medium">{{ $p->name }} <span class="font-normal text-slate-500">（{{ $inPkg->count() }} 張）</span></span>
                    <span class="text-slate-500">{{ tw($p->built_at) }}{{ $p->released_at ? '　已上線 '.tw($p->released_at) : '' }}</span>
                </div>
                @if ($inPkg->isNotEmpty())
                    <ul class="mt-1 space-y-0.5 pl-3">
                        @foreach ($inPkg as $pi)
                            <li class="flex items-center gap-2">
                                <a href="{{ route('issues.show', $pi->id) }}" class="font-mono text-xs text-indigo-700 hover:underline">{{ $pi->issue_no }}</a>
                                <span class="truncate">{{ $pi->title }}</span>
                                <x-badge :status="$pi->status" scope="issue" />
                                @if ($canManage && ! $p->released_at)
                                    <form method="POST" action="{{ route('dev.packages.issues.remove', [$p->id, $pi->id]) }}" onsubmit="return confirm(@js(in_array($pi->status, $packageHandoffCodes, true) ? '把 '.$pi->issue_no.' 移出 '.$p->name.'？' : '把 '.$pi->issue_no.' 移出 '.$p->name.'？已送測的單會撤回送測、退回開發中，並通知測試人員。'))">
                                        @csrf @method('DELETE')
                                        <button class="text-xs text-slate-400 hover:text-red-600" title="移出更新包">✕</button>
                                    </form>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                @endif
                @php $cands = $packageCandidates; @endphp
                @if ($canManage && ! $p->released_at && $cands->isNotEmpty())
                    <details class="mt-1 pl-3">
                        <summary class="cursor-pointer text-xs text-indigo-700">套入問題單…</summary>
                        <form method="POST" action="{{ route('dev.packages.issues.add', $p->id) }}" class="mt-1 space-y-0.5" data-pkg-form>
                            @csrf
                            <div class="max-h-56 overflow-y-auto rounded border border-slate-200 p-2">
                                @foreach ($cands as $c)
                                    <label class="flex items-center gap-2 py-0.5">
                                        <input type="checkbox" name="issue_ids[]" value="{{ $c->id }}" data-tester="{{ $c->tester?->name }}" class="rounded border-slate-300">
                                        <span class="font-mono text-xs">{{ $c->issue_no }}</span>
                                        <span class="truncate">{{ $c->title }}</span>
                                        <x-badge :status="$c->status" scope="issue" />
                                        @if ($c->tester)<span class="text-xs text-slate-500">Test：{{ $c->tester->name }}</span>@endif
                                        @if ($c->package_id)<span class="text-xs text-amber-700">目前在 {{ $packages->firstWhere('id', $c->package_id)?->name ?? '其他更新包' }}</span>@endif
                                    </label>
                                @endforeach
                            </div>
                            <input type="hidden" name="assignee_test_id" value="">
                            <button type="button" data-pkg-open class="mt-1 rounded-md bg-indigo-600 px-3 py-1 text-xs text-white hover:bg-indigo-700">套入並送測（改為{{ $packageTarget }}）</button>
                        </form>
                    </details>
                @endif
            </div>
        @empty
            <p class="text-sm text-slate-500">尚無更新包。</p>
        @endforelse
    </section>
    {{-- tester picker shown when a package takes issues (they move to the next status = waiting for test) --}}
    <dialog id="pkg-tester" class="m-auto w-full max-w-md rounded-lg p-0 shadow-xl backdrop:bg-slate-900/40">
        <form method="dialog" class="space-y-3 p-4">
            <h3 class="text-base font-semibold">指定測試人員</h3>
            <p class="text-sm text-slate-600">勾選的 <span data-pkg-count></span> 張單會套入更新包，狀態改為「{{ $packageTarget }}」。</p>
            <select data-pkg-tester class="w-full rounded-md border border-slate-300 px-2 py-1.5 text-sm">
                <option value="" data-keep>沿用各單原本的 Test</option>
                @foreach ($testUsers as $tu)<option value="{{ $tu->id }}">{{ $tu->name }}</option>@endforeach
            </select>
            @if ($testUsers->isEmpty())<p class="text-xs text-red-600">這個專案還沒有 Test 人員，只能送測已經有 Test 負責人的單；要指派請先到專案管理加入成員。</p>@endif
            <p data-pkg-need class="hidden text-xs text-red-600">有單還沒有 Test 負責人，請選擇測試人員。</p>
            <div class="flex justify-end gap-2">
                <button value="cancel" class="rounded-md border border-slate-300 px-3 py-1.5 text-sm">取消</button>
                <button type="button" data-pkg-submit class="rounded-md bg-indigo-600 px-3 py-1.5 text-sm text-white hover:bg-indigo-700">確定送測</button>
            </div>
        </form>
    </dialog>
    <script>
        (() => {
            const dlg = document.getElementById('pkg-tester');
            if (!dlg) return;
            const sel = dlg.querySelector('[data-pkg-tester]');
            const keep = sel.querySelector('[data-keep]');
            const need = dlg.querySelector('[data-pkg-need]');
            let form = null;
            document.querySelectorAll('[data-pkg-open]').forEach((btn) => btn.addEventListener('click', () => {
                form = btn.closest('form');
                const picked = [...form.querySelectorAll('input[name="issue_ids[]"]:checked')];
                if (!picked.length) { alert('請先勾選要套入的問題單'); return; }
                const missing = picked.some((c) => !c.dataset.tester);
                keep.hidden = keep.disabled = missing; // every issue needs a tester
                sel.disabled = missing && sel.options.length < 2;
                sel.value = missing ? (sel.options[1]?.value ?? '') : '';
                need.classList.add('hidden');
                dlg.querySelector('[data-pkg-count]').textContent = picked.length;
                dlg.showModal();
            }));
            dlg.querySelector('[data-pkg-submit]').addEventListener('click', () => {
                if (!sel.value && keep.disabled) { need.classList.remove('hidden'); return; }
                form.querySelector('input[name=assignee_test_id]').value = sel.value;
                dlg.close();
                form.submit();
            });
        })();
    </script>
@endsection
