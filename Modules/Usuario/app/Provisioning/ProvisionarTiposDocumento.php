<?php

namespace Modules\Usuario\Provisioning;

use Modules\Core\Tenancy\Contracts\ProvisionaTenant;
use Modules\Core\Tenancy\Provisioning\DadosProvisionamento;
use Modules\Core\Tenancy\TenantAtual;
use Modules\Usuario\Actions\CriarTiposDocumentoPadraoAction;

/** Ordem 30: tipos de documento por omissão. */
class ProvisionarTiposDocumento implements ProvisionaTenant
{
    public function __construct(private readonly CriarTiposDocumentoPadraoAction $tipos) {}

    public function ordem(): int
    {
        return 30;
    }

    public function provisionar(TenantAtual $tenant, DadosProvisionamento $dados): void
    {
        $this->tipos->executar();
    }
}
