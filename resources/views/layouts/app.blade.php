<!DOCTYPE html>
<html lang="pt">

<head>
    @include('layouts.partials.head')
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @inertiaHead
</head>

@include('layouts.partials.body-open')

@include('layouts.partials.header')


<div class="d-flex flex-column flex-root app-root">

    <div class="app-page flex-column flex-column-fluid">

        @include('layouts.partials.sidebar')

        <div class="app-wrapper flex-column flex-row-fluid" id="kt_app_wrapper">

            {{-- @include('layouts.partials.main') --}}
             @inertia  {{-- aqui, no lugar do main --}}

        </div>


    </div>

</div>

@include('layouts.partials.scripts')
</body>
</html>
