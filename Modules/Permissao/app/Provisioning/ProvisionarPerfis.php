<?php

namespace Modules\Permissao\Provisioning;

use Modules\Core\Tenancy\Contracts\ProvisionaTenant;
use Modules\Core\Tenancy\Provisioning\DadosProvisionamento;
use Modules\Core\Tenancy\TenantAtual;
use Modules\Permissao\Actions\SincronizarPerfisDeSistemaAction;

/** Ordem 20: perfis de sistema e respectivas permissões. */
class ProvisionarPerfis implements ProvisionaTenant
{
    public function __construct(private readonly SincronizarPerfisDeSistemaAction $perfis) {}

    public function ordem(): int
    {
        return 20;
    }

    public function provisionar(TenantAtual $tenant, DadosProvisionamento $dados): void
    {
        $this->perfis->executar();
    }
}
