<?php

namespace Modules\Financeiro\Provisioning;

use Modules\Core\Tenancy\Contracts\ProvisionaTenant;
use Modules\Core\Tenancy\Provisioning\DadosProvisionamento;
use Modules\Core\Tenancy\TenantAtual;
use Modules\Financeiro\Models\ConfiguracaoMonetaria;

/**
 * Ordem 60: configuração monetária com os defaults. Idempotente.
 */
class ProvisionarConfiguracaoMonetaria implements ProvisionaTenant
{
    public function ordem(): int
    {
        return 60;
    }

    public function provisionar(TenantAtual $tenant, DadosProvisionamento $dados): void
    {
        ConfiguracaoMonetaria::doTenant();
    }
}
