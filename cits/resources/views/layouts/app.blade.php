<!DOCTYPE html>
<html lang="zh-Hant">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="app-base" content="{{ url('/') }}">
    <title>@yield('title', '首頁') - 客戶問題追蹤系統</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @stack('head')
</head>
<body class="min-h-screen bg-slate-50 text-slate-800 antialiased">
@php
    $u = auth()->user();
    $nav = [];
    if ($u) {
        if (($u->isClient() || $u->is_admin) && $u->can('ticket.view')) { $nav[] = ['客戶問題', '/tickets', 'tickets*']; }
        if ($u->seesInternal() && $u->can('issue.view')) { $nav[] = ['問題管理', '/pm', 'pm*']; }
        if ($u->seesInternal() && $u->can('issue.view')) { $nav[] = ['開發管理', '/dev', 'dev*']; }
        if ($u->seesInternal() && $u->can('issue.view')) { $nav[] = ['測試管理', '/test', 'test*']; }
        if ($u->seesInternal() && $u->can('issue.view')) { $nav[] = ['時序總覽', '/timeline', 'timeline*']; }
        if ($u->seesInternal() && $u->can('issue.view')) { $nav[] = ['報表', '/reports', 'reports*']; }
        if ($u->seesInternal() && $u->can('issue.view')) { $nav[] = ['MES 頁面', '/mes', 'mes*']; }
        if ($u->can('project.members') || $u->can('project.trash')) { $nav[] = ['專案管理', '/project-admin', 'project-admin*']; }
        if ($u->is_admin) { $nav[] = ['系統管理', '/admin', 'admin*']; }
        if ($u->is_admin) { $nav[] = ['郵件設定', '/admin/settings/mail', 'admin/settings/mail*']; }
        if ($u->is_admin) { $nav[] = ['匯出設定', '/admin/settings/export', 'admin/settings/export*']; }
    }
@endphp
<header class="border-b border-slate-200 bg-white shadow-sm" x-data="{ open: false }">
    <div class="mx-auto flex max-w-7xl flex-wrap items-center gap-x-4 gap-y-2 px-4 py-3">
        <a href="{{ url('/') }}" class="text-lg font-semibold text-indigo-700">客戶問題追蹤系統</a>

        @auth
            <form method="POST" action="{{ route('projects.switch') }}" class="flex items-center gap-2">
                @csrf
                <label for="project-switch" class="text-xs text-slate-500">專案</label>
                <select id="project-switch" name="project_id" onchange="this.form.submit()"
                        class="rounded-md border border-slate-300 bg-white py-1 pl-2 pr-8 text-sm focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500">
                    @if (! $currentProject)
                        <option value="">請選擇專案</option>
                    @endif
                    @foreach ($navProjects as $p)
                        <option value="{{ $p->id }}" @selected($currentProject && $currentProject->id === $p->id)>{{ $p->name }}</option>
                    @endforeach
                </select>
            </form>
            @if ($currentProject && $u->can('flow.view'))
                <a href="{{ url('/flows') }}"
                   class="rounded-md px-3 py-1 text-sm {{ request()->is('flows*') ? 'bg-indigo-50 font-medium text-indigo-700' : 'text-slate-600 ring-1 ring-slate-200 hover:bg-slate-100' }}">流程圖</a>
            @endif
            @if ($currentProject && $u->can('meeting.view'))
                <a href="{{ url('/meetings') }}"
                   class="rounded-md px-3 py-1 text-sm {{ request()->is('meetings*') ? 'bg-indigo-50 font-medium text-indigo-700' : 'text-slate-600 ring-1 ring-slate-200 hover:bg-slate-100' }}">會議</a>
            @endif

            <button type="button" class="ml-auto rounded-md border border-slate-300 px-2 py-1 text-sm md:hidden" @click="open = !open">選單</button>

            <div class="flex w-full flex-wrap items-center gap-x-3 gap-y-2 md:ml-auto md:w-auto" :class="open ? '' : 'hidden md:flex'">
                <span class="text-sm text-slate-600">{{ $u->account }} <span class="text-slate-400">&lt;{{ $u->email }}&gt;</span></span>
                <a href="{{ route('notifications.index') }}" class="relative text-sm text-slate-600 hover:text-indigo-700" title="通知">🔔@if ($unreadCount > 0)<span class="absolute -right-3 -top-2 rounded-full bg-red-600 px-1.5 text-xs font-medium text-white">{{ $unreadCount > 99 ? '99+' : $unreadCount }}</span>@endif</a>
                <a href="{{ route('manual.show') }}" class="text-sm text-slate-600 hover:text-indigo-700">操作手冊</a>
                <a href="{{ route('account.settings') }}" class="text-sm text-slate-600 hover:text-indigo-700">個人設定</a>
                <a href="{{ route('password.edit') }}" class="text-sm text-slate-600 hover:text-indigo-700">修改密碼</a>
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button type="submit" class="rounded-md bg-slate-100 px-3 py-1 text-sm text-slate-700 hover:bg-slate-200">登出</button>
                </form>
            </div>
        @endauth
    </div>

    @auth
        @if ($nav)
            <nav class="border-t border-slate-100 bg-white">
                <div class="mx-auto flex max-w-7xl flex-wrap gap-1 px-4 py-2">
                    @foreach ($nav as [$label, $href, $pattern])
                        <a href="{{ url($href) }}"
                           class="rounded-md px-3 py-1.5 text-sm {{ request()->is($pattern) ? 'bg-indigo-50 font-medium text-indigo-700' : 'text-slate-600 hover:bg-slate-100' }}">{{ $label }}</a>
                    @endforeach
                </div>
            </nav>
        @endif
    @endauth
</header>

<main class="mx-auto max-w-7xl px-4 py-6">
    @include('layouts._flash')
    @yield('content')
</main>
@auth
    @inject('idleAuth', 'App\Services\AuthService')
    @if ($idleAuth->idleMinutes() > 0)
        <div id="idle-warn" hidden class="fixed inset-x-0 bottom-4 z-50 mx-auto w-fit rounded-md border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-900 shadow-lg">
            閒置過久，將於 <span id="idle-left"></span> 秒後自動登出。
            <button type="button" id="idle-stay" class="ml-2 rounded-md bg-amber-600 px-3 py-1 text-white hover:bg-amber-700">繼續使用</button>
        </div>
        <script>
            (function () {
                const limit = {{ $idleAuth->idleMinutes() }} * 60000, warnAt = limit - 120000, key = 'cits.idle.last';
                const warn = document.getElementById('idle-warn'), left = document.getElementById('idle-left');
                const touch = () => { try { localStorage.setItem(key, String(Date.now())); } catch (e) {} };
                const last = () => { try { return Number(localStorage.getItem(key)) || 0; } catch (e) { return 0; } };
                let mine = Date.now();
                touch();
                // Requests from this page refresh the server-side clock too, except background timers.
                const orig = window.fetch.bind(window);
                window.fetch = function (input, init) {
                    const h = new Headers((init && init.headers) || (input instanceof Request ? input.headers : undefined));
                    const p = orig(input, init);
                    if (!h.has('X-CITS-Background')) p.then(() => { mine = Date.now(); touch(); }, () => {});
                    return p;
                };
                document.getElementById('idle-stay').addEventListener('click', () => {
                    fetch(@js(route('session.ping')), { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
                        .then((r) => { if (r.status === 401) location.href = @js(route('home')); });
                });
                setInterval(() => {
                    const idle = Date.now() - Math.max(mine, last()); // other tabs share the session
                    if (idle >= limit + 5000) { location.href = @js(route('home')); return; }
                    warn.hidden = idle < warnAt;
                    if (!warn.hidden) left.textContent = String(Math.max(0, Math.ceil((limit - idle) / 1000)));
                }, 1000);
            })();
        </script>
    @endif
@endauth
@stack('scripts')
</body>
</html>
