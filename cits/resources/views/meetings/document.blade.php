<!doctype html>
<html lang="zh-Hant">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title }}</title>
    @vite('resources/css/app.css')
    @if (str_contains($html, 'class="cits-flow"'))
        @vite('resources/js/flow/index.js')
    @endif
</head>
<body class="p-4">
    @include('flows._doc', ['html' => $html])
</body>
</html>
