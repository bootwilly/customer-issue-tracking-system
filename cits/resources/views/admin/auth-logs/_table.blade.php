<table class="min-w-full divide-y divide-slate-200 text-sm">
    <thead class="bg-slate-50 text-left text-xs text-slate-500">
        <tr>
            <th class="px-3 py-2">時間</th><th class="px-3 py-2">事件</th>
            @if ($withUser)<th class="px-3 py-2">使用者</th>@endif
            <th class="px-3 py-2">IP</th><th class="px-3 py-2">瀏覽器</th>
        </tr>
    </thead>
    <tbody class="divide-y divide-slate-100">
        @forelse ($rows as $l)
            <tr>
                <td class="whitespace-nowrap px-3 py-2 text-slate-600">{{ tw($l->created_at, 'Y-m-d H:i:s') }}</td>
                <td class="whitespace-nowrap px-3 py-2">
                    <span class="{{ in_array($l->event, ['login_fail', 'login_locked'], true) || $l->detail ? 'text-red-700' : '' }}">{{ $events[$l->event] ?? $l->event }}</span>
                    @if ($l->detail)<span class="text-xs text-slate-500">（{{ $details[$l->detail] ?? $l->detail }}）</span>@endif
                </td>
                @if ($withUser)
                    <td class="whitespace-nowrap px-3 py-2">
                        @if ($l->user){{ $l->user->account }} <span class="text-xs text-slate-500">{{ $l->user->name }}</span>
                        @else<span class="text-slate-500">{{ $l->login ?: '—' }}</span>@endif
                        @if ($l->user && $l->login && mb_strtolower($l->login) !== mb_strtolower($l->user->account))<span class="text-xs text-slate-400">（輸入 {{ $l->login }}）</span>@endif
                    </td>
                @endif
                <td class="whitespace-nowrap px-3 py-2 font-mono text-xs">
                    {{ $l->ip ?: '—' }}
                    @if ($l->forwarded_for && $l->forwarded_for !== $l->ip)<div class="text-slate-400" title="X-Forwarded-For">{{ $l->forwarded_for }}</div>@endif
                </td>
                <td class="max-w-xs truncate px-3 py-2 text-xs text-slate-500" title="{{ $l->user_agent }}">{{ $l->user_agent }}</td>
            </tr>
        @empty
            <tr><td colspan="{{ $withUser ? 5 : 4 }}" class="px-3 py-6 text-center text-slate-500">沒有紀錄。</td></tr>
        @endforelse
    </tbody>
</table>
