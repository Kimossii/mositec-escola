<?php

namespace Modules\Core\Tenancy\Console;

use Modules\Core\Tenancy\Contracts\CatalogoDeTenants;
use Modules\Core\Tenancy\Enums\EstadoTenant;
use Modules\Core\Tenancy\TenantAtual;
use Symfony\Component\Console\Input\InputOption;

/**
 * Para comandos Artisan de dados de escola que operam sobre UM tenant: acrescenta `--tenant=CODIGO`
 * (obrigatória; na linha de comandos não há tenant por omissão). `tenantEscolhido()` devolve o
 * tenant Activo ou escreve o erro e devolve null; o comando termina então com FAILURE.
 */
trait EscolheUmTenant
{
    protected function configure(): void
    {
        parent::configure();

        $this->addOption('tenant', null, InputOption::VALUE_REQUIRED, 'Código do tenant (MOSI-000000)');
    }

    protected function tenantEscolhido(): ?TenantAtual
    {
        $codigo = trim((string) $this->option('tenant'));

        if ($codigo === '') {
            $this->error('Indique o tenant com --tenant=CODIGO.');

            return null;
        }

        $tenant = app(CatalogoDeTenants::class)->porCodigo($codigo);

        if ($tenant === null) {
            $this->error("Tenant '{$codigo}' não encontrado.");

            return null;
        }

        if ($tenant->estado !== EstadoTenant::ACTIVO) {
            $this->error("O tenant {$tenant->codigo} está " . strtolower($tenant->estado->label()) . ': só se opera em tenants activos.');

            return null;
        }

        return $tenant;
    }
}
