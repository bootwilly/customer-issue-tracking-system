@extends('layouts.app')
@section('title', '選擇專案')
@section('content')
    <h1 class="mb-4 text-xl font-semibold">選擇專案</h1>
    @if ($projects->isEmpty())
        <x-empty>目前沒有可使用的專案，請聯絡系統管理員。</x-empty>
    @else
        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
            @foreach ($projects as $p)
                <form method="POST" action="{{ route('projects.switch') }}">
                    @csrf
                    <input type="hidden" name="project_id" value="{{ $p->id }}">
                    <button type="submit" class="w-full rounded-lg border border-slate-200 bg-white p-4 text-left shadow-sm hover:border-indigo-400 hover:shadow">
                        <div class="font-medium text-slate-900">{{ $p->name }}</div>
                        <div class="mt-1 text-xs text-slate-500">{{ $p->code }}</div>
                    </button>
                </form>
            @endforeach
        </div>
    @endif
@endsection
