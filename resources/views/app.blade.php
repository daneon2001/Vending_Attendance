<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">
<<<<<<< HEAD
        <meta name="app-base-path" content="{{ config('app.base_path') }}">
        <meta name="app-api-base-url" content="{{ config('app.api_base_url') }}">
=======
        @php
            $configuredBasePath = trim((string) env('VITE_APP_BASE_PATH', ''), '/');
            $appUrlPath = trim((string) (parse_url(config('app.url'), PHP_URL_PATH) ?: ''), '/');
            $appBasePath = $configuredBasePath !== '' ? '/'.$configuredBasePath : ($appUrlPath !== '' ? '/'.$appUrlPath : '');
        @endphp
        <meta name="app-base-path" content="{{ $appBasePath }}">
>>>>>>> dev

        <title inertia>{{ config('app.name', 'Laravel') }}</title>

        <!-- Fonts -->
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=figtree:400,500,600&display=swap" rel="stylesheet" />

        <!-- Scripts -->
        @routes
        @vite(['resources/js/app.js', "resources/js/Pages/{$page['component']}.vue"])
        @inertiaHead
    </head>
    <body class="font-sans antialiased">
        @inertia
    </body>
</html>
