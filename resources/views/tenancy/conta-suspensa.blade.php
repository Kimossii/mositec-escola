<!DOCTYPE html>
<html lang="pt">

<head>
    <base href="/" />
    <title>Conta suspensa | MosiTec</title>
    <meta charset="utf-8"/>
    <meta name="viewport" content="width=device-width, initial-scale=1"/>
    <meta name="robots" content="noindex, nofollow"/>
    <link rel="shortcut icon" href="{{ asset('themes/metronic/assets/media/logos/favicon.ico') }}"/>
    <link href="{{ asset('themes/metronic/assets/plugins/global/plugins.bundle.css') }}" rel="stylesheet" type="text/css"/>
    <link href="{{ asset('themes/metronic/assets/css/style.bundle.css') }}" rel="stylesheet" type="text/css"/>
</head>

{{-- Página sem sessão, sem JavaScript e sem dados do tenant (spec §6 e §17.4). --}}
<body id="kt_app_body" class="app-blank">
<div class="d-flex flex-column flex-lg-row flex-column-fluid" style="min-height: 100vh;">
    <div class="d-flex flex-column flex-lg-row-fluid w-lg-50 p-10 order-2 order-lg-1">
        <div class="d-flex flex-center flex-column flex-lg-row-fluid">
            <div class="w-lg-500px p-10 text-center">
                <h1 class="text-dark fw-bolder mb-3">Conta suspensa</h1>
                <div class="text-gray-500 fw-semibold fs-6 mb-8">
                    O acesso a esta escola está temporariamente indisponível.
                </div>
                <div class="bg-body-secondary rounded p-6 text-gray-700 fs-6">
                    Se considera que se trata de um engano, contacte a MosiTec ou o responsável pela sua escola.
                </div>
            </div>
        </div>
    </div>

    <div class="d-flex flex-lg-row-fluid w-lg-50 order-1 order-lg-2" style="background-color: #0F172A;">
        <div class="d-flex flex-column flex-center py-15 px-10 w-100">
            <img alt="Logo" src="/themes/metronic/assets/media/logos/default-small.svg" class="h-30px mb-10"/>
            <h2 class="d-none d-lg-block text-white fs-2qx fw-bolder text-center mb-7">
                Gestão Acadêmica MosiTec
            </h2>
        </div>
    </div>
</div>
</body>

</html>
