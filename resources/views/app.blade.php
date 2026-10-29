<!doctype html>
<html lang="en">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="theme-color" content="#00b6fe">
        <link rel="icon" href="/favicon.ico">
        @vite(['resources/css/app.css', 'resources/js/app.ts'])
        <x-inertia::head><title>{{ config('app.name') }}</title></x-inertia::head>
    </head>
    <body><x-inertia::app /></body>
</html>
