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

    /*
    | Confirmação de email: a ligação enviada ao utilizador expira ao fim deste tempo, e cada utilizador só pode pedir
    | um número limitado de ligações por hora (evita encher a caixa de correio de alguém).
    */
    'confirmacao_email' => [
        'validade_minutos' => (int) env('EMAIL_CONFIRMACAO_VALIDADE_MIN', 60),
        'pedidos_por_hora' => (int) env('EMAIL_CONFIRMACAO_PEDIDOS_HORA', 3),
    ],

    /*
    | Recuperação de senha por email («esqueci a senha»). Só os perfis desta lista (slugs de Perfil) a podem usar, e só quando a
    | conta tem APENAS esse perfil: administradores, funcionários, professores e alunos nunca recuperam por email, falam com o
    | administrador da escola. A ligação expira e só serve uma vez (deixa de valer assim que a senha muda).
    */
    'recuperacao_senha' => [
        'perfis' => ['encarregado'],
        'validade_minutos' => (int) env('EMAIL_RECUPERACAO_VALIDADE_MIN', 60),
        'pedidos_por_hora' => (int) env('EMAIL_RECUPERACAO_PEDIDOS_HORA', 3),
    ],
];
