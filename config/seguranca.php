<?php

return [
    /*
    | Interruptor único para quando a app passar a servir HTTPS. Enquanto for false (rede interna, HTTP) nada
    | que dependa de HTTPS é ligado: sem HSTS e com cookie de sessão sem a marca `secure`. Ligar quando o
    | HTTPS estiver estável em todos os domínios das escolas.
    */
    'forcar_https' => (bool) env('FORCAR_HTTPS', false),

    // Segundos do HSTS. Começar curto (1 dia) e subir depois de uma semana sem problemas.
    'hsts_max_age' => (int) env('HSTS_MAX_AGE', 86400),

    // Política de conteúdo só em modo relatório: regista violações no browser sem bloquear nada.
    'csp_report_only' => (bool) env('CSP_REPORT_ONLY', false),
];
