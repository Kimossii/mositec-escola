<!DOCTYPE html>
<html lang="pt">

<head>
    <meta charset="utf-8"/>
    <meta name="viewport" content="width=device-width, initial-scale=1"/>
    <meta name="robots" content="noindex, nofollow"/>
    <title>MosiTec | Plataforma</title>
    <link rel="shortcut icon" href="{{ asset('themes/metronic/assets/media/logos/favicon.ico') }}?v=mosi"/>
    <link rel="icon" type="image/png" sizes="32x32" href="{{ asset('themes/metronic/assets/media/logos/mosi-favicon-32.png') }}?v=mosi"/>
    <link rel="apple-touch-icon" href="{{ asset('themes/metronic/assets/media/logos/mosi-apple-touch-icon.png') }}?v=mosi"/>

    {{-- Só o tema: o painel não leva analytics, Sidebar, Header nem menu da escola. --}}
    <link href="{{ asset('themes/metronic/assets/plugins/global/plugins.bundle.css') }}" rel="stylesheet" type="text/css"/>
    <link href="{{ asset('themes/metronic/assets/css/style.bundle.css') }}" rel="stylesheet" type="text/css"/>
    <script src="{{ asset('themes/metronic/assets/js/core/frame-guard.js') }}"></script>

    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @inertiaHead
</head>

<body id="kt_app_body" class="app-blank">
<script src="{{ asset('themes/metronic/assets/js/core/theme-boot.js') }}"></script>

<div class="d-flex flex-column flex-root" id="kt_app_root">
    @inertia
</div>

@include('layouts.partials.scripts-base')
</body>
</html>
