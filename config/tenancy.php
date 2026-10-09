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
    | Domínios-raiz da MosiTec: um domínio de tenant sob uma destas raízes (ou sob o host de APP_URL)
    | é do tipo Subdomínio; qualquer outro é Domínio personalizado. Lista separada por vírgulas.
    */
    'dominios_raiz' => array_values(array_filter(array_map('trim', explode(',', (string) env('TENANCY_DOMINIOS_RAIZ', 'mositec.ao'))))),

    /*
    | Caminhos que respondem em qualquer host, sem tenant: só a verificação de saúde.
    */
    'caminhos_sem_tenant' => ['up'],

    /*
    | Subdomínios que nenhum tenant pode registar.
    */
    'subdominios_reservados' => ['www', 'api', 'admin', 'plataforma', 'mail', 'app'],

    /*
    | Provisionadores que têm de estar etiquetados para criar um tenant (FQCN como texto: o módulo Tenant
    | não importa módulos de negócio). Se algum faltar (módulo desactivado), nada é criado.
    */
    'provisionadores_esperados' => [
        'Modules\\Estabelecimento\\Provisioning\\ProvisionarEstabelecimento',
        'Modules\\Permissao\\Provisioning\\ProvisionarPerfis',
        'Modules\\Usuario\\Provisioning\\ProvisionarTiposDocumento',
        'Modules\\Autenticacao\\Provisioning\\ProvisionarAdministradorInicial',
    ],

    /*
    | Tabelas sem tenant_id por desenho: catálogo do produto e gestão de tenants.
    | As regras exists/unique sobre estas tabelas não são filtradas por tenant.
    | Toda a tabela é exactamente uma de: tem tenant_id, consta em tabelas_globais ou em
    | tabelas_infraestrutura (verificado por TenancyEsquemaTest). Qualquer outra é tenant-scoped.
    */
    'tabelas_globais' => [
        'tenants',
        'domains',
        'modulos',
        'acoes',
        'licencas', // legado; ver spec §18
        'super_admins', // Plataforma MosiTec: operadores, sem escola
        'plataforma_auditoria', // Plataforma MosiTec: rasto das acções, sobrevive aos tenants
        'cambios_plataforma', // Financeiro: câmbio padrão da plataforma, partilhado por todas as escolas
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
];
