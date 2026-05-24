<!DOCTYPE html>
<html lang="pt">

<head>
    @include('layouts.partials.head')
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @inertiaHead
</head>

@include('layouts.partials.body-open')

{{-- Barra de navegação horizontal, barra de navegação superior, conteúdo principal. --}}
{{-- Header Vue monta aqui, no mesmo lugar do blade anterior --}}
<div id="app-header"></div>


<div class="d-flex flex-column flex-root app-root">

    <div class="app-page flex-column flex-column-fluid">

        {{-- Barra de navegação vertical a esquerda, barra de navegação superior, conteúdo principal. --}}
        @include('layouts.partials.sidebar')

        <div class="app-wrapper flex-column flex-row-fluid" id="kt_app_wrapper">

             @inertia  {{-- aqui, no lugar do main --}}

        </div>


    </div>

</div>

@include('layouts.partials.scripts')
</body>
</html>
