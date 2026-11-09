<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover, user-scalable=no">
        <meta name="csrf-token" content="{{ csrf_token() }}">
        <meta name="theme-color" content="#bfeaff">
        <script>
            // Theme before first paint (no flash): saved choice, else the phone's setting. See ThemeToggle.vue.
            (function () {
                var saved = null;
                try { saved = localStorage.getItem('bombbusters.theme'); } catch (e) {}
                var dark = saved ? saved === 'dark' : window.matchMedia('(prefers-color-scheme: dark)').matches;
                document.documentElement.dataset.theme = dark ? 'dark' : 'light';
                document.querySelector('meta[name=theme-color]').content = dark ? '#1c1838' : '#bfeaff';
            })();
        </script>
        <link rel="manifest" href="/manifest.webmanifest">
        <link rel="apple-touch-icon" href="/icon-512.png">
        <meta name="mobile-web-app-capable" content="yes">
        <meta name="apple-mobile-web-app-capable" content="yes">
        <meta name="apple-mobile-web-app-status-bar-style" content="default">

        <title inertia>{{ config('app.name') }}</title>

        @vite(['resources/css/app.css', 'resources/js/app.js'])
        @inertiaHead
    </head>
    <body class="min-h-dvh font-sans text-ink antialiased">
        @inertia
    </body>
</html>
