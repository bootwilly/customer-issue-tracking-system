@extends('layouts.app')
@section('title', '匯出設定')
@section('content')
    @php $in = 'w-full rounded-md border border-slate-300 px-3 py-2 text-sm focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500'; @endphp
    <div class="max-w-xl rounded-lg bg-white p-6 shadow-sm">
        <h1 class="mb-4 text-xl font-semibold">Excel 匯出設定</h1>
        <form method="POST" action="{{ route('admin.export.update') }}" class="space-y-4">
            @csrf
            @method('PUT')
            <div>
                <label for="max_rows" class="mb-1 block text-sm font-medium">單次匯出筆數上限</label>
                <input id="max_rows" name="max_rows" type="number" min="1" max="100000" value="{{ old('max_rows', $maxRows) }}" class="{{ $in }}">
                <p class="mt-1 text-xs text-slate-500">符合篩選條件的資料超過上限時不會匯出，會提示使用者縮小範圍。數量越大，產生檔案越久（主機單次請求上限 60 秒）。</p>
            </div>
            <button type="submit" class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700">儲存</button>
        </form>
    </div>
@endsection
