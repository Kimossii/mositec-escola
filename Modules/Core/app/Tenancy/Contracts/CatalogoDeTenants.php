<?php

namespace Modules\Core\Tenancy\Contracts;

use Modules\Core\Tenancy\TenantAtual;

/**
 * Como o runtime de tenancy (jobs, comandos) descobre tenants fora de um pedido HTTP.
 * O módulo Tenant implementa-o e regista-o no container; o Core só conhece este contrato.
 * Devolve o tenant em qualquer estado: decidir se corre é de quem consulta.
 */
interface CatalogoDeTenants
{
    public function porId(int $id): ?TenantAtual;

    public function porCodigo(string $codigo): ?TenantAtual;

    /** @return list<TenantAtual> todos os tenants, por id crescente */
    public function todos(): array;
}
