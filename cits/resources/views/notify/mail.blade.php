@extends('layouts.app')
@section('title', '郵件設定')
@section('content')
    @php $in = 'w-full rounded-md border border-slate-300 px-3 py-2 text-sm focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500'; @endphp
    <div class="max-w-xl space-y-6">
        <div class="rounded-lg bg-white p-6 shadow-sm">
            <h1 class="mb-4 text-xl font-semibold">SMTP 郵件設定</h1>
            <form method="POST" action="{{ route('admin.mail.update') }}" class="space-y-4">
                @csrf
                @method('PUT')
                <label class="flex items-center gap-2 text-sm">
                    <input type="checkbox" name="enabled" value="1" class="rounded border-slate-300" @checked(old('enabled', $s['enabled']))> 啟用郵件寄送
                </label>
                <div>
                    <label for="host" class="mb-1 block text-sm font-medium">SMTP 主機</label>
                    <input id="host" name="host" type="text" value="{{ old('host', $s['host']) }}" class="{{ $in }}">
                </div>
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label for="port" class="mb-1 block text-sm font-medium">連接埠</label>
                        <input id="port" name="port" type="number" value="{{ old('port', $s['port']) }}" class="{{ $in }}">
                    </div>
                    <div>
                        <label for="encryption" class="mb-1 block text-sm font-medium">加密方式</label>
                        <select id="encryption" name="encryption" class="{{ $in }}">
                            @foreach (['none' => '無', 'tls' => 'TLS (STARTTLS, 587)', 'ssl' => 'SSL (465)'] as $v => $l)
                                <option value="{{ $v }}" @selected(old('encryption', $s['encryption']) === $v)>{{ $l }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>
                <div>
                    <label for="username" class="mb-1 block text-sm font-medium">帳號</label>
                    <input id="username" name="username" type="text" value="{{ old('username', $s['username']) }}" autocomplete="off" class="{{ $in }}">
                </div>
                <div>
                    <label for="password" class="mb-1 block text-sm font-medium">密碼 @if ($s['password_set'])<span class="text-xs text-emerald-600">（已設定，留空則保持不變）</span>@endif</label>
                    <input id="password" name="password" type="password" autocomplete="new-password" class="{{ $in }}">
                    @if ($s['password_set'])
                        <label class="mt-2 flex items-center gap-2 text-sm text-slate-600"><input type="checkbox" name="clear_password" value="1" class="rounded border-slate-300"> 清除密碼</label>
                    @endif
                </div>
                <div>
                    <label for="from_address" class="mb-1 block text-sm font-medium">寄件人 Email</label>
                    <input id="from_address" name="from_address" type="email" value="{{ old('from_address', $s['from_address']) }}" class="{{ $in }}">
                </div>
                <div>
                    <label for="from_name" class="mb-1 block text-sm font-medium">寄件人名稱</label>
                    <input id="from_name" name="from_name" type="text" value="{{ old('from_name', $s['from_name']) }}" class="{{ $in }}">
                </div>
                <button type="submit" class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700">儲存</button>
            </form>
        </div>

        <div class="rounded-lg bg-white p-6 shadow-sm">
            <h2 class="mb-3 text-lg font-semibold">寄送測試郵件</h2>
            <p class="mb-3 text-xs text-slate-500">使用已儲存的設定立即寄送，請先儲存再測試。</p>
            <form method="POST" action="{{ route('admin.mail.test') }}" class="flex gap-2">
                @csrf
                <input name="to" type="email" required value="{{ old('to', auth()->user()->email) }}" class="{{ $in }}">
                <button type="submit" class="whitespace-nowrap rounded-md bg-slate-700 px-4 py-2 text-sm font-medium text-white hover:bg-slate-800">寄送測試</button>
            </form>
        </div>
    </div>
@endsection
