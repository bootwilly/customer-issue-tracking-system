@extends('layouts.app')
@section('title', 'MES 匯入預覽')
@section('content')
    @php
        $labels = ['new' => '新增', 'exists' => '已存在', 'invalid' => '無效'];
        $colors = ['new' => 'bg-emerald-100 text-emerald-800', 'exists' => 'bg-slate-200 text-slate-600', 'invalid' => 'bg-red-100 text-red-700'];
        $newCount = collect($rows)->where('status', 'new')->count();
    @endphp
    <div class="mb-4 flex items-center justify-between">
        <h1 class="text-xl font-semibold">匯入預覽</h1>
        <a href="{{ route('mes.index') }}" class="text-sm text-slate-600 hover:underline">返回</a>
    </div>
    @if (! $rows)
        <x-empty>沒有可匯入的路徑。</x-empty>
    @else
        <div class="overflow-x-auto rounded-lg bg-white shadow-sm">
            <table class="min-w-full divide-y divide-slate-200 text-sm">
                <thead class="bg-slate-50 text-left text-xs text-slate-500">
                    <tr><th class="px-3 py-2">路徑</th><th class="px-3 py-2">動作</th><th class="px-3 py-2">自動建立資料夾</th></tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @foreach ($rows as $r)
                        <tr>
                            <td class="px-3 py-2 font-mono text-xs">{{ $r['path'] }}</td>
                            <td class="px-3 py-2"><span class="rounded-full px-2 py-0.5 text-xs {{ $colors[$r['status']] }}">{{ $labels[$r['status']] }}</span></td>
                            <td class="px-3 py-2 text-slate-600">{{ $r['note'] ?: implode(' / ', $r['folders']) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
    @if ($newCount > 0)
        <form method="POST" action="{{ route('mes.import.apply') }}" class="mt-4">
            @csrf
            <input type="hidden" name="paths" value="{{ $paths }}">
            <button type="submit" class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700">確認匯入 {{ $newCount }} 筆</button>
        </form>
    @endif
@endsection
