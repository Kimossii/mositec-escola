<?php

return [
    /*
    | Interruptor geral. Desligado, o gate e os limites são inertes (as escolas actuais não bloqueiam no deploy).
    */
    'activo' => (bool) env('LICENCIAMENTO_ACTIVO', false),

    /*
    | Chaves públicas Ed25519 que verificam as licenças, por `kid` => chave em base64. Suporta rotação de chaves.
    */
    'chaves_publicas' => [
        // 'k1' => 'base64...',
    ],

    /*
    | Só o emissor (instalação central) tem chave privada; as escolas deixam-na vazia. A chave NUNCA vai para o `.env` nem
    | para a config: fica num FICHEIRO fora da raiz web (conteúdo = a chave em base64) e aqui só se guarda o CAMINHO.
    | O ficheiro é lido no momento de assinar e a chave não é guardada em memória.
    */
    'emissor' => [
        'chave_privada_ficheiro' => env('LICENCIAMENTO_CHAVE_PRIVADA_FICHEIRO', ''),
        'kid' => env('LICENCIAMENTO_KID', ''),
    ],

    // Valores por omissão, sobreponíveis por plano e por licença.
    'tolerancia_dias_padrao' => (int) env('LICENCIAMENTO_TOLERANCIA_DIAS', 7),
    'aviso_dias_padrao' => (int) env('LICENCIAMENTO_AVISO_DIAS', 30),

    // Nomes de rota (aceita `*`) que o administrador continua a poder usar com a licença bloqueada: a página da licença,
    // o logout e as exportações/descargas que já existem nos módulos (nada novo, nada editado nos módulos).
    'rotas_permitidas_ao_admin' => [
        'licenca.*',
        'logout',
        // A troca obrigatória da senha temporária vem primeiro (segurança) e não expõe dados da escola; sem isto, gate e troca redireccionam em ciclo.
        'senha.alterar',
        'senha.alterar.store',
        // «Os meus ficheiros»: ver e descarregar o que já foi exportado (partilhar/eliminar ficam de fora).
        'ficheiros.index',
        'ficheiros.descarregar',
        'ficheiros.descarregar-pasta',
        // Exportações dos módulos.
        'usuario.exportar',
        'docentes.exportar',
        'atribuicoes-docentes.exportar',
        'avaliacao.notas.exportar',
        'avaliacao.pautas.exportar',
        'financeiro.dividas.exportar',
        'financeiro.relatorios.recebimentos',
    ],

    // Aliases dos módulos licenciáveis; extensível, e depende do plano/licença.
    'modulos_licenciaveis' => [
        'financeiro',
        'avaliacao',
    ],

    // Nome legível de cada módulo licenciável (o alias é usado se faltar).
    'modulos_nomes' => [
        'financeiro' => 'Financeiro',
        'avaliacao' => 'Avaliação',
    ],
];
