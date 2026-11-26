{{-- Watch toggle. Vars: $action (POST url), $watching (bool), $watchers (optional collection, internal pages only) --}}
<form method="POST" action="{{ $action }}" class="inline-flex items-center gap-2">
    @csrf
    <input type="hidden" name="on" value="{{ $watching ? 0 : 1 }}">
    <button type="submit" class="rounded-full border px-2.5 py-0.5 text-xs {{ $watching ? 'border-amber-300 bg-amber-50 text-amber-800 hover:bg-amber-100' : 'border-slate-300 text-slate-600 hover:bg-slate-50' }}"
            title="{{ $watching ? '取消後不再收到這張單的通知（指派給你的仍會通知）' : '有變更或新留言時通知我' }}">
        {{ $watching ? '★ 關注中' : '☆ 關注' }}
    </button>
    @isset($watchers)
        @if ($watchers->isNotEmpty())
            <span class="text-xs text-slate-500" title="{{ $watchers->pluck('name')->join('、') }}">{{ $watchers->count() }} 人關注</span>
        @endif
    @endisset
</form>
