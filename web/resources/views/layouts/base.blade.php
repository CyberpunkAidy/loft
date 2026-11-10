<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@hasSection('title')@yield('title') · @endif{{ isset($studio) && $studio ? $studio->name : config('app.name') }}</title>
    @hasSection('description')<meta name="description" content="@yield('description')">@endif
    <link rel="icon" href="{{ asset('favicon.ico') }}" sizes="48x48">
    @if (isset($studio) && $studio)
        @php($favicon = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 48 48"><rect width="48" height="48" rx="13" fill="'.$studio->accent().'"/><text x="24" y="33" text-anchor="middle" font-family="Arial,Helvetica,sans-serif" font-size="26" font-weight="700" fill="'.$studio->accentInk().'">'.e($studio->initial()).'</text></svg>')
        <link rel="icon" href="data:image/svg+xml,{{ rawurlencode($favicon) }}" type="image/svg+xml">
        <meta name="theme-color" content="{{ $studio->accent() }}">
    @else
        <link rel="icon" href="{{ asset('favicon.svg') }}" type="image/svg+xml">
        <meta name="theme-color" content="#5b4bf5">
    @endif
    <link rel="apple-touch-icon" href="{{ asset('apple-touch-icon.png') }}">
    <link rel="preload" href="{{ asset('fonts/inter-latin-wght-normal.woff2') }}" as="font" type="font/woff2" crossorigin>
    <link rel="preload" href="{{ asset('fonts/bricolage-grotesque-latin-wght-normal.woff2') }}" as="font" type="font/woff2" crossorigin>
    <link rel="stylesheet" href="{{ asset('css/app.css') }}?v={{ @filemtime(public_path('css/app.css')) }}">
    @if (isset($studio) && $studio)
        <style>:root { --accent: {{ $studio->accent() }}; --accent-ink: {{ $studio->accentInk() }}; --accent-text: {{ $studio->accentText() }}; }</style>
    @endif
    @stack('head')
</head>
<body class="@yield('body-class')">
@yield('body')
<script src="{{ asset('js/app.js') }}?v={{ @filemtime(public_path('js/app.js')) }}" defer></script>
</body>
</html>
