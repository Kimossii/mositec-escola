<?php

return [

    /*
    | Como o tenant é resolvido em cada pedido:
    |   dominio  → pelo host do pedido, na tabela domains (instalação partilhada, cloud, desenvolvimento)
    |   unico    → o único tenant da instalação, ignorando o host (instalação local ou dedicada)
    */
    'modo' => env('TENANCY_MODO', 'dominio'),

    /*
    | Hosts que não pertencem a nenhum tenant. Os pedidos seguem sem tenant resolvido.
    | Lista separada por vírgulas.
    */
    'hosts_centrais' => array_values(array_filter(array_map('trim', explode(',', (string) env('TENANCY_HOSTS_CENTRAIS', ''))))),

    /*
    | Caminhos que respondem em qualquer host, sem tenant: só a verificação de saúde.
    */
    'caminhos_sem_tenant' => ['up'],

    /*
    | Subdomínios que nenhum tenant pode registar.
    */
    'subdominios_reservados' => ['www', 'api', 'admin', 'plataforma', 'mail', 'app'],

    /*
    | Tabelas sem tenant_id por desenho: catálogo do produto e gestão de tenants.
    | As regras exists/unique sobre estas tabelas não são filtradas por tenant.
    | Qualquer tabela que NÃO esteja numa das três listas abaixo é tratada como tenant-scoped.
    */
    'tabelas_globais' => [
        'tenants',
        'domains',
        'modulos',
        'acoes',
        'licencas', // legado; ver spec §18
    ],

    /*
    | Tabelas do framework.
    */
    'tabelas_infraestrutura' => [
        'cache',
        'cache_locks',
        'jobs',
        'job_batches',
        'failed_jobs',
        'sessions',
        'migrations',
    ],

    /*
    | LISTA DE TRANSIÇÃO. Tabelas de tenant que ainda não receberam tenant_id.
    | Cada plano seguinte retira daqui as tabelas que converte.
    | O último plano exige que esteja vazia e remove esta chave.
    */
    'tabelas_por_converter' => [],
];
