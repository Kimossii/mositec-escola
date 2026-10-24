<?php

namespace Modules\Financeiro\Provisioning;

use Modules\Core\Tenancy\Contracts\ProvisionaTenant;
use Modules\Core\Tenancy\Provisioning\DadosProvisionamento;
use Modules\Core\Tenancy\TenantAtual;
use Modules\Financeiro\Models\RegraCobranca;

/**
 * Ordem 50: regras de cobrança com os defaults. Idempotente.
 */
class ProvisionarRegrasCobranca implements ProvisionaTenant
{
    public function ordem(): int
    {
        return 50;
    }

    public function provisionar(TenantAtual $tenant, DadosProvisionamento $dados): void
    {
        RegraCobranca::doTenant();
    }
}
