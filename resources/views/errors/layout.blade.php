<!DOCTYPE html>
<html lang="pt">

<head>
    <title>@yield('titulo') | MosiTec</title>
    <meta charset="utf-8"/>
    <meta name="viewport" content="width=device-width, initial-scale=1"/>
    <meta name="robots" content="noindex, nofollow"/>
    <link rel="shortcut icon" href="/themes/metronic/assets/media/logos/favicon.ico?v=mosi"/>
    <link rel="icon" type="image/png" sizes="32x32" href="/themes/metronic/assets/media/logos/mosi-favicon-32.png?v=mosi"/>
    <link href="/themes/metronic/assets/plugins/global/plugins.bundle.css" rel="stylesheet" type="text/css"/>
    <link href="/themes/metronic/assets/css/style.bundle.css" rel="stylesheet" type="text/css"/>
</head>

{{-- Página de erro sem sessão, sem JavaScript e sem consultas à base de dados: tem de funcionar mesmo quando o resto falhou. --}}
<body id="kt_app_body" class="app-blank">
<div class="d-flex flex-column flex-center text-center p-10" style="min-height: 100vh;">
    <img src="/images/mosi/@yield('imagem', 'mosi-erro').svg" width="150" alt="" class="mb-6"/>
    <div class="text-gray-500 fw-bold fs-6 mb-2">Erro @yield('codigo')</div>
    <h1 class="text-dark fw-bolder mb-3">@yield('titulo')</h1>
    <div class="text-gray-600 fw-semibold fs-5 mb-8 mw-500px">@yield('mensagem')</div>
    <div class="d-flex gap-3">
        <a href="/" class="btn btn-sm btn-dark">Ir para o início</a>
        @yield('accao')
    </div>
</div>
</body>

</html>
