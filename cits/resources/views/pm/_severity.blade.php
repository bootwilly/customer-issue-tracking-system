@php
    $n = (int) ($n ?? 0);
    $color = $n >= 4 ? 'bg-red-500' : ($n === 3 ? 'bg-amber-500' : 'bg-emerald-500');
@endphp
@if ($n > 0)
    <span class="inline-flex items-center gap-0.5" title="嚴重度 {{ $n }}／5">
        @for ($k = 1; $k <= 5; $k++)
            <span class="h-2 w-2 rounded-full {{ $k <= $n ? $color : 'bg-slate-200' }}"></span>
        @endfor
    </span>
@else
    <span class="text-slate-400">-</span>
@endif
