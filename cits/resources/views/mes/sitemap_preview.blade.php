@extends('layouts.app')
@section('title', 'Web.sitemap 匯入預覽')
@section('content')
    @php
        $labels = ['new' => '新增', 'update' => '已存在（更新）', 'same' => '不變', 'invalid' => '無效'];
        $colors = ['new' => 'bg-emerald-100 text-emerald-800', 'update' => 'bg-amber-100 text-amber-800', 'same' => 'bg-slate-200 text-slate-600', 'invalid' => 'bg-red-100 text-red-700'];
        $changes = $counts['new'] + $counts['update'];
    @endphp
    <div class="mb-4 flex items-center justify-between">
        <h1 class="text-xl font-semibold">Web.sitemap 匯入預覽</h1>
        <a href="{{ route('mes.index') }}" class="text-sm text-slate-600 hover:underline">返回</a>
    </div>
    <p class="mb-3 text-sm text-slate-600">新增 {{ $counts['new'] }}　更新 {{ $counts['update'] }}　不變 {{ $counts['same'] }}　無效 {{ $counts['invalid'] }}（預覽不會寫入，也不會停用或刪除 sitemap 中沒有的既有頁面）</p>
    <div class="overflow-x-auto rounded-lg bg-white shadow-sm">
        <table class="min-w-full divide-y divide-slate-200 text-sm">
            <thead class="bg-slate-50 text-left text-xs text-slate-500">
                <tr><th class="px-3 py-2">名稱</th><th class="px-3 py-2">類型</th><th class="px-3 py-2">路徑</th><th class="px-3 py-2">動作</th><th class="px-3 py-2">說明</th></tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @foreach ($rows as $r)
                    <tr>
                        <td class="px-3 py-2" style="padding-left: {{ 0.75 + $r['depth'] * 1.5 }}rem">{{ $r['name'] }}</td>
                        <td class="px-3 py-2 text-xs text-slate-500">{{ $r['kind'] === 'folder' ? '資料夾' : '頁面' }}</td>
                        <td class="px-3 py-2 font-mono text-xs">{{ $r['path'] }}</td>
                        <td class="px-3 py-2"><span class="rounded-full px-2 py-0.5 text-xs {{ $colors[$r['status']] }}">{{ $labels[$r['status']] }}</span></td>
                        <td class="px-3 py-2 text-slate-600">{{ $r['note'] }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    @if ($changes > 0)
        <form method="POST" action="{{ route('mes.sitemap.apply') }}" class="mt-4">
            @csrf
            <input type="hidden" name="payload" value="{{ $payload }}">
            <button type="submit" class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700">確認匯入（新增 {{ $counts['new'] }}、更新 {{ $counts['update'] }}）</button>
        </form>
    @endif
@endsection
