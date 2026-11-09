<!DOCTYPE html>
<html lang="pt-BR">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="robots" content="noindex">
        <title>{{ config('app.name') }}</title>
        @unless (request()->routeIs('shop.*', 'menu.*'))
            <link rel="manifest" href="/manifest.webmanifest">
            <meta name="theme-color" content="#c8321f">
            <link rel="apple-touch-icon" href="/icons/apple-touch-icon.png">
            <meta name="apple-mobile-web-app-capable" content="yes">
            <meta name="mobile-web-app-capable" content="yes">
            <meta name="apple-mobile-web-app-title" content="Delivery">
        @endunless
        @fonts
        @routes
        @vite(['resources/css/app.css', 'resources/js/app.js'])
        @inertiaHead
    </head>
    <body>
        @inertia
    </body>
</html>
