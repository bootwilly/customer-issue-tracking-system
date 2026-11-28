@extends('layouts.app')
@section('title', '專案管理 - 回收筒')
@section('content')
    @include('project-admin._tabs', ['active' => 'trash'])
    @if ($flows->isEmpty() && $meetings->isEmpty() && $issues->isEmpty())
        <x-empty>回收筒是空的。</x-empty>
    @endif
    @if ($issues->isNotEmpty())
        <h2 class="mb-2 text-base font-semibold">內部單</h2>
        <div class="mb-6 overflow-x-auto rounded-lg bg-white shadow-sm">
            <table class="min-w-full divide-y divide-slate-200 text-sm">
                <thead class="bg-slate-50 text-left text-xs text-slate-500">
                    <tr><th class="px-3 py-2">內部單</th><th class="px-3 py-2">建立</th><th class="px-3 py-2">客戶單</th><th class="px-3 py-2">刪除</th><th class="px-3 py-2"></th></tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @foreach ($issues as $i)
                        <tr>
                            <td class="px-3 py-2"><span class="font-medium">{{ $i->issue_no }}</span> {{ $i->title }}</td>
                            <td class="px-3 py-2 text-xs text-slate-500">{{ tw($i->created_at) }}　{{ $i->creator?->name }}</td>
                            <td class="px-3 py-2 text-xs">{{ $i->tickets->pluck('ticket_no')->implode('、') ?: '-' }}</td>
                            <td class="px-3 py-2 text-xs text-slate-500">{{ tw($i->deleted_at) }}　{{ $i->deleter?->name }}</td>
                            <td class="px-3 py-2">
                                <div class="flex flex-wrap items-center gap-3">
                                    <form method="POST" action="{{ route('padmin.trash.issues.restore', $i->id) }}">
                                        @csrf
                                        <button type="submit" class="rounded-md bg-slate-100 px-3 py-1 text-xs hover:bg-slate-200">還原</button>
                                    </form>
                                    <form method="POST" action="{{ route('padmin.trash.issues.purge', $i->id) }}" class="flex items-center gap-1"
                                          onsubmit="return confirm('永久刪除後無法復原，留言、補充說明、附件、標籤、相依、客戶單連結都會刪除。確定？')">
                                        @csrf @method('DELETE')
                                        <input type="text" name="confirm_no" required placeholder="輸入單號確認" class="w-40 rounded border border-slate-300 px-2 py-1 text-xs">
                                        <button type="submit" class="rounded-md bg-red-600 px-3 py-1 text-xs text-white hover:bg-red-700">永久刪除</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
    @if ($flows->isNotEmpty())
        <h2 class="mb-2 text-base font-semibold">流程圖</h2>
        <div class="overflow-x-auto rounded-lg bg-white shadow-sm">
            <table class="min-w-full divide-y divide-slate-200 text-sm">
                <thead class="bg-slate-50 text-left text-xs text-slate-500">
                    <tr><th class="px-3 py-2">流程圖</th><th class="px-3 py-2">版本</th><th class="px-3 py-2">刪除</th><th class="px-3 py-2"></th></tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @foreach ($flows as $f)
                        @php $title = $f->title !== '' ? $f->title : $f->draft_title; @endphp
                        <tr>
                            <td class="px-3 py-2 font-medium">{{ $title }}</td>
                            <td class="px-3 py-2">{{ $f->version > 0 ? 'v'.$f->version : '未發布' }}</td>
                            <td class="px-3 py-2 text-xs text-slate-500">{{ $f->deleted_at?->timezone('Asia/Taipei')->format('Y-m-d H:i') }}　{{ $f->deleter?->name }}</td>
                            <td class="px-3 py-2">
                                <div class="flex flex-wrap items-center gap-3">
                                    <form method="POST" action="{{ route('padmin.trash.restore', $f->id) }}">
                                        @csrf
                                        <button type="submit" class="rounded-md bg-slate-100 px-3 py-1 text-xs hover:bg-slate-200">還原</button>
                                    </form>
                                    <form method="POST" action="{{ route('padmin.trash.purge', $f->id) }}" class="flex items-center gap-1"
                                          onsubmit="return confirm('永久刪除後無法復原，所有版本都會刪除。確定？')">
                                        @csrf @method('DELETE')
                                        <input type="text" name="confirm_title" required placeholder="輸入標題確認" class="w-40 rounded border border-slate-300 px-2 py-1 text-xs">
                                        <button type="submit" class="rounded-md bg-red-600 px-3 py-1 text-xs text-white hover:bg-red-700">永久刪除</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
    @if ($meetings->isNotEmpty())
        <h2 class="mb-2 mt-6 text-base font-semibold">會議</h2>
        <div class="overflow-x-auto rounded-lg bg-white shadow-sm">
            <table class="min-w-full divide-y divide-slate-200 text-sm">
                <thead class="bg-slate-50 text-left text-xs text-slate-500">
                    <tr><th class="px-3 py-2">會議</th><th class="px-3 py-2">時間</th><th class="px-3 py-2">刪除</th><th class="px-3 py-2"></th></tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @foreach ($meetings as $m)
                        <tr>
                            <td class="px-3 py-2 font-medium">{{ $m->title }}</td>
                            <td class="px-3 py-2">{{ tw($m->start_at) }}</td>
                            <td class="px-3 py-2 text-xs text-slate-500">{{ tw($m->deleted_at) }}　{{ $m->deleter?->name }}</td>
                            <td class="px-3 py-2">
                                <div class="flex flex-wrap items-center gap-3">
                                    <form method="POST" action="{{ route('padmin.trash.meetings.restore', $m->id) }}">
                                        @csrf
                                        <button type="submit" class="rounded-md bg-slate-100 px-3 py-1 text-xs hover:bg-slate-200">還原</button>
                                    </form>
                                    <form method="POST" action="{{ route('padmin.trash.meetings.purge', $m->id) }}" class="flex items-center gap-1"
                                          onsubmit="return confirm('永久刪除後無法復原，討論事項與留言都會刪除。確定？')">
                                        @csrf @method('DELETE')
                                        <input type="text" name="confirm_title" required placeholder="輸入會議主題確認" class="w-40 rounded border border-slate-300 px-2 py-1 text-xs">
                                        <button type="submit" class="rounded-md bg-red-600 px-3 py-1 text-xs text-white hover:bg-red-700">永久刪除</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
@endsection
