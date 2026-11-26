@extends('layouts.app')
@section('title', '專案管理 - 成員')
@section('content')
    @php $roleLabels = app(\App\Services\WorkflowService::class)->lookupMapAll('role_label'); @endphp
    @include('project-admin._tabs', ['active' => 'members'])
    <form method="POST" action="{{ route('padmin.members.store') }}" class="mb-4 flex flex-wrap items-center gap-2">
        @csrf
        <select name="user_id" required class="rounded-md border border-slate-300 py-1.5 pl-2 pr-8 text-sm">
            <option value="">選擇要加入的帳號</option>
            @foreach ($candidates as $c)
                <option value="{{ $c->id }}">{{ $c->account }} - {{ $c->name }}（{{ $c->isClient() ? '客戶' : '內部' }}）</option>
            @endforeach
        </select>
        <button type="submit" class="rounded-md bg-indigo-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-indigo-700">加入</button>
    </form>

    @if ($members->isEmpty())
        <x-empty>此專案尚無成員。</x-empty>
    @else
        <div class="space-y-3">
            @foreach ($members as $m)
                @php $mu = $m['user']; @endphp
                <div class="rounded-lg bg-white p-4 shadow-sm">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <form method="POST" action="{{ route('padmin.members.update', $mu->id) }}" class="flex flex-wrap items-center gap-x-4 gap-y-2">
                            @csrf @method('PUT')
                            <div class="min-w-40">
                                <div class="font-medium">{{ $mu->account }} <span class="text-slate-500">{{ $mu->name }}</span></div>
                                <div class="text-xs text-slate-400">{{ $mu->isClient() ? '客戶' : '內部' }}</div>
                            </div>
                            @if ($m['locked'])
                                <span class="text-sm text-slate-600">{{ implode('、', array_map(fn ($r) => $roleLabels[$r] ?? $r, $m['roles'])) ?: '（無角色）' }}</span>
                                <span class="text-xs text-slate-400">{{ $mu->id === auth()->id() ? '無法變更自己' : '由系統管理員管理' }}</span>
                            @else
                                @foreach ($rolesByType[$mu->user_type] ?? [] as $role)
                                    <label class="flex items-center gap-1.5 text-sm">
                                        <input type="checkbox" name="roles[]" value="{{ $role->name }}" @checked(in_array($role->name, $m['roles'], true)) class="rounded border-slate-300">
                                        {{ $roleLabels[$role->name] ?? $role->name }}
                                    </label>
                                @endforeach
                                <button type="submit" class="rounded-md bg-slate-100 px-3 py-1 text-sm hover:bg-slate-200">儲存角色</button>
                            @endif
                        </form>
                        @unless ($m['locked'])
                            <form method="POST" action="{{ route('padmin.members.destroy', $mu->id) }}" onsubmit="return confirm('確定把 {{ $mu->account }} 移出專案？角色與流程圖編輯資格都會一併失去。')">
                                @csrf @method('DELETE')
                                <button type="submit" class="text-sm text-red-600 hover:underline">移出</button>
                            </form>
                        @endunless
                    </div>
                </div>
            @endforeach
        </div>
    @endif
@endsection
