<?php

namespace Modules\Estabelecimento\Provisioning;

use Modules\Core\Tenancy\Contracts\ProvisionaTenant;
use Modules\Core\Tenancy\Provisioning\DadosProvisionamento;
use Modules\Core\Tenancy\TenantAtual;
use Modules\Estabelecimento\Models\Estabelecimento;

/**
 * Ordem 10: estabelecimento mínimo, só com o nome e configurado_em nulo.
 * O administrador completa os dados institucionais no primeiro acesso (spec §8.5).
 */
class ProvisionarEstabelecimento implements ProvisionaTenant
{
    public function ordem(): int
    {
        return 10;
    }

    public function provisionar(TenantAtual $tenant, DadosProvisionamento $dados): void
    {
        Estabelecimento::create(['nome' => $dados->nomeEstabelecimento]);
    }
}
