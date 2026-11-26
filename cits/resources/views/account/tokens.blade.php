@extends('layouts.app')
@section('title', 'API 權杖')
@section('content')
    @php
        $endpoint = url('/mcp');
        $sample = json_encode([
            'mcpServers' => ['cits' => ['type' => 'http', 'url' => $endpoint, 'headers' => ['Authorization' => 'Bearer '.($plain ?? '<你的權杖>')]]],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    @endphp
    <div class="max-w-3xl space-y-6">
        <div class="rounded-lg bg-white p-6 shadow-sm">
            <h1 class="mb-4 text-xl font-semibold">API 權杖（MCP 唯讀）</h1>
            @if ($plain)
                <div class="mb-4 rounded-md border border-amber-300 bg-amber-50 p-3 text-sm">
                    <p class="mb-1 font-medium">新權杖（只會顯示這一次）：</p>
                    <code class="block break-all rounded bg-white p-2 select-all">{{ $plain }}</code>
                </div>
            @endif
            <form method="POST" action="{{ route('account.tokens.store') }}" class="flex flex-wrap items-end gap-3">
                @csrf
                <div>
                    <label for="name" class="mb-1 block text-sm font-medium">名稱</label>
                    <input id="name" name="name" required maxlength="100" value="{{ old('name') }}"
                           class="rounded-md border border-slate-300 px-3 py-2 text-sm focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500">
                </div>
                <div>
                    <label for="expiry" class="mb-1 block text-sm font-medium">有效期限</label>
                    <select id="expiry" name="expiry" class="rounded-md border border-slate-300 px-3 py-2 text-sm">
                        @foreach ($expiryChoices as $c)
                            <option value="{{ $c }}">{{ $c === 'none' ? '永不過期' : $c.' 天' }}</option>
                        @endforeach
                    </select>
                </div>
                <button type="submit" class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700">建立權杖</button>
            </form>
        </div>

        <div class="rounded-lg bg-white p-6 shadow-sm">
            <h2 class="mb-3 font-semibold">我的權杖</h2>
            <table class="w-full text-sm">
                <thead><tr class="border-b text-left text-slate-500"><th class="py-2">名稱</th><th>建立</th><th>最後使用</th><th>到期</th><th>狀態</th><th></th></tr></thead>
                <tbody>
                @forelse ($tokens as $t)
                    <tr class="border-b">
                        <td class="py-2">{{ $t->name }}</td>
                        <td>{{ tw($t->created_at) }}</td>
                        <td>{{ $t->last_used_at ? tw($t->last_used_at) : '—' }}</td>
                        <td>{{ $t->expires_at ? tw($t->expires_at) : '永不' }}</td>
                        <td>
                            @if ($t->revoked_at) 已撤銷
                            @elseif ($t->expires_at && $t->expires_at->isPast()) 已過期
                            @else 有效 @endif
                        </td>
                        <td class="text-right">
                            @if (! $t->revoked_at)
                                <form method="POST" action="{{ route('account.tokens.revoke', $t->id) }}" onsubmit="return confirm('確定撤銷此權杖？')">
                                    @csrf
                                    <button type="submit" class="text-red-600 hover:underline">撤銷</button>
                                </form>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="py-4 text-center text-slate-500">尚無權杖</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>

        <div class="rounded-lg bg-white p-6 shadow-sm">
            <h2 class="mb-2 font-semibold">MCP 端點</h2>
            <p class="mb-3 text-sm"><code>{{ $endpoint }}</code>（唯讀，僅能查詢你有權限的專案）</p>
            <pre class="overflow-x-auto rounded bg-slate-900 p-3 text-xs text-slate-100">{{ $sample }}</pre>
        </div>
    </div>
@endsection
