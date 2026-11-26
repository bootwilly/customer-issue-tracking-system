@extends('layouts.guest')
@section('title', '重設密碼')
@section('content')
    @if (! $enabled)
        <p class="text-sm text-slate-700">系統未啟用郵件，請聯絡管理員重設密碼。</p>
    @else
        <form method="POST" action="{{ route('password.reset.store', $token) }}" class="space-y-4">
            @csrf
            <div>
                <label for="email" class="mb-1 block text-sm font-medium">Email</label>
                <input id="email" name="email" type="text" value="{{ old('email', $email) }}" required
                       class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500">
            </div>
            @foreach ([['password', '新密碼（至少 '.$minLength.' 碼）'], ['password_confirmation', '確認新密碼']] as [$name, $label])
                <div>
                    <label for="{{ $name }}" class="mb-1 block text-sm font-medium">{{ $label }}</label>
                    <input id="{{ $name }}" name="{{ $name }}" type="password" required autocomplete="new-password"
                           class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500">
                </div>
            @endforeach
            <button type="submit" class="w-full rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700">重設密碼</button>
        </form>
    @endif
@endsection
