<?php

return [
    'name' => 'Plataforma',

    /*
    | Duração da sessão do painel, em minutos. Mais curta que a das escolas (SESSION_LIFETIME):
    | quem tem acesso ao painel gere todas as escolas.
    */
    'sessao_minutos' => (int) env('PLATAFORMA_SESSAO_MINUTOS', 60),
];
