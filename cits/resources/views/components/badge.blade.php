@props(['status', 'scope' => 'ticket'])
@php
    $meta = app(\App\Repositories\SettingRepository::class)->workflowStatus($scope, (string) $status);
    // Colour is derived from status flags (not literal codes).
    $stageColors = ['PM' => 'bg-indigo-100 text-indigo-800', 'DEV' => 'bg-sky-100 text-sky-800', 'TEST' => 'bg-violet-100 text-violet-800', 'RELEASE' => 'bg-teal-100 text-teal-800'];
    $tabColors = ['open' => 'bg-blue-100 text-blue-800', 'pending' => 'bg-amber-100 text-amber-800', 'closed' => 'bg-emerald-100 text-emerald-800', 'cancelled' => 'bg-slate-200 text-slate-600'];
    if (! $meta) {
        $cls = 'bg-slate-100 text-slate-600';
    } elseif ($meta['is_excluded']) {
        $cls = 'bg-slate-200 text-slate-600';
    } elseif ($meta['is_released']) {
        $cls = 'bg-emerald-100 text-emerald-800';
    } else {
        $cls = $tabColors[$meta['client_tab'] ?? ''] ?? $stageColors[$meta['stage'] ?? ''] ?? 'bg-slate-100 text-slate-700';
    }
@endphp
<span {{ $attributes->merge(['class' => "badge inline-flex items-center whitespace-nowrap rounded-full px-2.5 py-0.5 text-xs font-medium {$cls}"]) }}>{{ $meta['name'] ?? $status }}</span>
