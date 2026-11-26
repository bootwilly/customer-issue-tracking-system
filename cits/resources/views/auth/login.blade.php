@extends('layouts.guest')
@section('title', '登入')
@section('content')
    <form method="POST" action="{{ route('login.store') }}" class="space-y-4">
        @csrf
        <div>
            <label for="login" class="mb-1 block text-sm font-medium">帳號或 Email</label>
            <input id="login" name="login" type="text" value="{{ old('login') }}" required autofocus autocomplete="username"
                   class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500">
        </div>
        <div>
            <label for="password" class="mb-1 block text-sm font-medium">密碼</label>
            <input id="password" name="password" type="password" required autocomplete="current-password"
                   class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500">
        </div>
        <label class="flex items-center gap-2 text-sm text-slate-600">
            <input type="checkbox" name="remember" value="1" class="rounded border-slate-300"> 記住我
        </label>
        <button type="submit" class="w-full rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700">登入</button>
        <p class="text-center text-sm"><a href="{{ route('password.forgot') }}" class="text-indigo-700 hover:underline">忘記密碼？</a></p>
    </form>
@endsection
