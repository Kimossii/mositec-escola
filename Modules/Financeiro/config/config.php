<?php

return [
    'name' => 'Financeiro',

    'propinas' => [
        // Interruptor de segurança (plano F2, R-F2.1): a geração de propinas (manual e automática) só fica
        // disponível depois de concluída a protecção das matrículas (F3). Para testar localmente:
        // FINANCEIRO_GERACAO_PROPINAS=true no .env e `php artisan config:clear` se a configuração estiver em cache.
        'geracao_activa' => (bool) env('FINANCEIRO_GERACAO_PROPINAS', false),
    ],
];
