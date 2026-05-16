<!DOCTYPE html>
<html lang="pt">
<head>
    @include('layouts.partials.head')
</head>

<body id="kt_app_body">

@include('layouts.partials.header')

<div class="d-flex flex-column flex-root app-root">

    <div class="app-page flex-column flex-column-fluid">

        @include('layouts.partials.sidebar')

        <div class="app-wrapper flex-column flex-row-fluid">

            <div class="app-main flex-column flex-row-fluid">

                @yield('content')

            </div>

            @include('layouts.partials.footer')

        </div>
    </div>
</div>

@include('layouts.partials.scripts')

</body>
</html>
