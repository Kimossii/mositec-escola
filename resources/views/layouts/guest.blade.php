<!DOCTYPE html>
<html lang="pt">

<head>
    @include('layouts.partials.head')
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @inertiaHead
</head>

<body id="kt_app_body" class="app-blank">

<div class="d-flex flex-column flex-root" id="kt_app_root">
    @inertia
</div>

@include('layouts.partials.scripts')
</body>
</html>
