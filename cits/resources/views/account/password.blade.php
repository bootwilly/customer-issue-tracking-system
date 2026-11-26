@extends('layouts.app')
@section('title', '修改密碼')
@section('content')
    <div class="max-w-md rounded-lg bg-white p-6 shadow-sm">
        <h1 class="mb-4 text-xl font-semibold">修改密碼</h1>
        <form method="POST" action="{{ route('password.update') }}" class="space-y-4">
            @csrf
            @method('PUT')
            @foreach ([['current_password', '目前密碼'], ['password', '新密碼（至少 '.$minLength.' 碼）'], ['password_confirmation', '確認新密碼']] as [$name, $label])
                <div>
                    <label for="{{ $name }}" class="mb-1 block text-sm font-medium">{{ $label }}</label>
                    <input id="{{ $name }}" name="{{ $name }}" type="password" required autocomplete="{{ $name === 'current_password' ? 'current-password' : 'new-password' }}"
                           class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500">
                </div>
            @endforeach
            <button type="submit" class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700">更新密碼</button>
        </form>
    </div>
@endsection
