@extends('layouts.app')
@section('title', '通知')
@section('content')
    <div class="rounded-lg bg-white p-6 shadow-sm">
        <div class="mb-4 flex flex-wrap items-center gap-3">
            <h1 class="text-xl font-semibold">通知</h1>
            <a href="{{ route('notifications.index') }}" class="rounded-md px-2 py-1 text-sm {{ $unreadOnly ? 'text-slate-600 hover:bg-slate-100' : 'bg-indigo-50 text-indigo-700' }}">全部</a>
            <a href="{{ route('notifications.index', ['filter' => 'unread']) }}" class="rounded-md px-2 py-1 text-sm {{ $unreadOnly ? 'bg-indigo-50 text-indigo-700' : 'text-slate-600 hover:bg-slate-100' }}">未讀</a>
            <form method="POST" action="{{ route('notifications.readAll') }}" class="ml-auto">
                @csrf
                <button type="submit" class="rounded-md bg-slate-100 px-3 py-1 text-sm text-slate-700 hover:bg-slate-200">全部標示已讀</button>
            </form>
        </div>
        @forelse ($items as $n)
            <form method="POST" action="{{ route('notifications.read', $n->id) }}" class="border-t border-slate-100 first:border-t-0">
                @csrf
                <button type="submit" class="flex w-full items-center gap-3 px-2 py-3 text-left text-sm hover:bg-slate-50">
                    <span class="h-2 w-2 shrink-0 rounded-full {{ $n->read_at ? 'bg-transparent' : 'bg-indigo-600' }}"></span>
                    <span class="flex-1 {{ $n->read_at ? 'text-slate-500' : 'font-medium text-slate-800' }}">{{ $n->title }}</span>
                    <span class="text-xs text-slate-400">{{ tw($n->created_at) }}</span>
                </button>
            </form>
        @empty
            <x-empty>沒有通知</x-empty>
        @endforelse
        <div class="mt-4">{{ $items->links() }}</div>
    </div>
@endsection
