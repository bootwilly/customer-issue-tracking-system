@extends('layouts.app')
@section('title', '專案管理 - 異動紀錄')
@section('content')
    @include('project-admin._tabs', ['active' => 'events'])
    @if ($events->isEmpty())
        <x-empty>目前沒有異動紀錄。</x-empty>
    @else
        <div class="overflow-x-auto rounded-lg bg-white shadow-sm">
            <table class="min-w-full divide-y divide-slate-200 text-sm">
                <thead class="bg-slate-50 text-left text-xs text-slate-500">
                    <tr><th class="px-3 py-2">時間</th><th class="px-3 py-2">操作者</th><th class="px-3 py-2">動作</th><th class="px-3 py-2">內容</th></tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @foreach ($events as $e)
                        @php $p = $e->payload ?? []; @endphp
                        <tr>
                            <td class="whitespace-nowrap px-3 py-2 text-xs text-slate-500">{{ $e->created_at?->timezone('Asia/Taipei')->format('Y-m-d H:i') }}</td>
                            <td class="px-3 py-2">{{ $e->actor?->name }}</td>
                            <td class="px-3 py-2">{{ $e->label }}</td>
                            <td class="px-3 py-2 text-xs text-slate-600">
                                @if ($e->subject_type === 'issue' && isset($p['issue']))<span class="font-medium">{{ $p['issue'] }}</span>@endif
                                {{ $p['title'] ?? (isset($p['account']) ? $p['account'].' '.($p['name'] ?? '') : '') }}
                                @if (isset($p['version']))　v{{ $p['version'] }}@endif
                                @if (isset($p['versions']))　共 {{ $p['versions'] }} 版@endif
                                @if ($e->to_label && $e->from_label !== $e->to_label)　{{ $e->from_label ?? '—' }} → {{ $e->to_label }}@endif
                                @if (isset($p['from'], $p['to']) && is_array($p['from']))　{{ implode('、', $p['from']) ?: '無' }} → {{ implode('、', $p['to']) ?: '無' }}@endif
                                @if (! empty($p['note']))　「{{ $p['note'] }}」@endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
@endsection
