@extends('layouts.guest')
@section('title', '忘記密碼')
@section('content')
    @if (! $enabled)
        <p class="text-sm text-slate-700">系統未啟用郵件，請聯絡管理員重設密碼。</p>
    @else
        <form method="POST" action="{{ route('password.forgot.send') }}" class="space-y-4">
            @csrf
            <div>
                <label for="login" class="mb-1 block text-sm font-medium">帳號或 Email</label>
                <input id="login" name="login" type="text" value="{{ old('login') }}" required autofocus
                       class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500">
            </div>
            <button type="submit" class="w-full rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700">寄送重設連結</button>
        </form>
    @endif
    <p class="mt-4 text-center text-sm"><a href="{{ route('home') }}" class="text-indigo-700 hover:underline">返回登入</a></p>
@endsection
