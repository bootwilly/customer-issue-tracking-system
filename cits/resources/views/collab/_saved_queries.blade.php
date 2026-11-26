{{-- "My queries" bar of a list page. Vars: $scope (pm|dev|test|client). Self-contained: reads the user's saved queries. --}}
@php
    $sqSvc = app(\App\Services\Collab\SavedQueryService::class);
    $sqPid = (int) app(\App\Support\CurrentProject::class)->id();
    $sqUser = auth()->user();
    $sqList = $sqSvc->list($sqUser, $sqPid, $scope);
    $sqCurrent = $sqSvc->clean($scope, request()->query());
    $sqBtn = 'rounded px-1.5 text-xs text-slate-500 hover:bg-slate-100';
@endphp
@if ($sqSvc->canUse($sqUser, $sqPid, $scope))
    <div class="mb-3 flex flex-wrap items-center gap-2 text-sm">
        <span class="text-xs text-slate-500">我的查詢：</span>
        @forelse ($sqList as $sq)
            <a href="{{ $sqSvc->url($sq) }}" class="rounded-full border px-2.5 py-0.5 text-xs {{ $sq->params == $sqCurrent ? 'border-indigo-400 bg-indigo-50 text-indigo-800' : 'border-slate-300 text-slate-700 hover:bg-slate-50' }}">{{ $sq->name }}</a>
        @empty
            <span class="text-xs text-slate-400">（尚無，設定好篩選後按「儲存目前條件」）</span>
        @endforelse
        <details class="relative">
            <summary class="cursor-pointer list-none rounded-md border border-slate-300 px-2 py-0.5 text-xs text-slate-600 hover:bg-slate-50">儲存目前條件／管理</summary>
            <div class="absolute z-20 mt-1 w-80 space-y-3 rounded-md border border-slate-200 bg-white p-3 shadow-lg">
                <form method="POST" action="{{ route('collab.queries.store') }}" class="flex gap-2">
                    @csrf
                    <input type="hidden" name="scope" value="{{ $scope }}">
                    <input type="hidden" name="qs" value="{{ http_build_query($sqCurrent) }}">
                    <input type="text" name="name" required maxlength="100" placeholder="查詢名稱（同名會覆蓋）" class="min-w-0 flex-1 rounded-md border border-slate-300 px-2 py-1 text-sm">
                    <button class="rounded-md bg-indigo-600 px-2.5 py-1 text-xs font-medium text-white hover:bg-indigo-700">儲存</button>
                </form>
                @if ($sqList->isNotEmpty())
                    <ul class="divide-y divide-slate-100 text-sm">
                        @foreach ($sqList as $sq)
                            <li class="flex items-center gap-1 py-1">
                                <form method="POST" action="{{ route('collab.queries.rename', $sq->id) }}" class="flex min-w-0 flex-1 gap-1">
                                    @csrf
                                    <input type="text" name="name" value="{{ $sq->name }}" maxlength="100" required class="min-w-0 flex-1 rounded border border-transparent px-1 py-0.5 text-sm hover:border-slate-300 focus:border-indigo-400">
                                    <button class="{{ $sqBtn }}" title="改名">改名</button>
                                </form>
                                <form method="POST" action="{{ route('collab.queries.move', $sq->id) }}">@csrf<input type="hidden" name="dir" value="up"><button class="{{ $sqBtn }}" title="上移">↑</button></form>
                                <form method="POST" action="{{ route('collab.queries.move', $sq->id) }}">@csrf<input type="hidden" name="dir" value="down"><button class="{{ $sqBtn }}" title="下移">↓</button></form>
                                <form method="POST" action="{{ route('collab.queries.destroy', $sq->id) }}" onsubmit="return confirm(@js('刪除查詢「'.$sq->name.'」？'))">@csrf @method('DELETE')<button class="rounded px-1.5 text-xs text-red-600 hover:bg-red-50" title="刪除">✕</button></form>
                            </li>
                        @endforeach
                    </ul>
                @endif
                <p class="text-xs text-slate-400">只存篩選條件；「我負責的」等條件套用時以當下登入的人計算。</p>
            </div>
        </details>
    </div>
@endif
