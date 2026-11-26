@extends('layouts.app')
@section('title', '個人設定')
@section('content')
    <div class="max-w-md space-y-4 rounded-lg bg-white p-6 shadow-sm">
        <h1 class="text-xl font-semibold">個人設定</h1>
        <form method="POST" action="{{ route('account.settings.update') }}" class="space-y-4">
            @csrf
            @method('PUT')
            <label class="flex items-center gap-2 text-sm">
                <input type="checkbox" name="email_notify" value="1" class="rounded border-slate-300" @checked(old('email_notify', auth()->user()->email_notify))> 以 Email 接收通知
            </label>
            <button type="submit" class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700">儲存</button>
        </form>
        <div class="border-t border-slate-100 pt-3 text-sm">
            <a href="{{ url('/account/tokens') }}" class="text-indigo-700 hover:underline">API Token</a>
        </div>
    </div>
@endsection
