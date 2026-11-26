<!DOCTYPE html>
<html lang="zh-Hant">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', '登入') - 客戶問題追蹤系統</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="flex min-h-screen items-center justify-center bg-slate-100 px-4 text-slate-800 antialiased">
<div class="w-full max-w-sm">
    <h1 class="mb-6 text-center text-2xl font-semibold text-indigo-700">客戶問題追蹤系統</h1>
    <div class="rounded-lg bg-white p-6 shadow">
        @include('layouts._flash')
        @yield('content')
    </div>
</div>
</body>
</html>
