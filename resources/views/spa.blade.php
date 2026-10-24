<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ config('app.name') }}</title>
    {{-- Runtime facts the SPA needs before it can call the API (which host is a provider storefront). --}}
    <script>window.__APP__ = @json(['baseDomain' => config('app.base_domain')]);</script>
    @viteReactRefresh
    @vite(['resources/css/app.css', 'resources/js/main.tsx'])
</head>
<body>
    <div id="root"></div>
</body>
</html>
