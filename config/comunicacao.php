<?php

return [
    /*
    | Limite de emails por tenant e por hora. Protege a reputação do domínio de envio partilhado: uma escola
    | que dispare em massa (ou com um erro em ciclo) não pode degradar a entrega das outras. Os essenciais têm
    | tecto próprio e mais folgado, para nunca competirem com os opcionais.
    */
    'limites_por_hora' => [
        'essencial' => (int) env('EMAIL_LIMITE_ESSENCIAL_HORA', 300),
        'opcional' => (int) env('EMAIL_LIMITE_OPCIONAL_HORA', 1000),
    ],
];
