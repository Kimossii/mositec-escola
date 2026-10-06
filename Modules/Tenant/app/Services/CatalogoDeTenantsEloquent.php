<?php

namespace Modules\Tenant\Services;

use Modules\Core\Tenancy\Contracts\CatalogoDeTenants;
use Modules\Core\Tenancy\TenantAtual;
use Modules\Tenant\Models\Tenant;

/** Implementação do contrato do Core: jobs e comandos descobrem tenants sem conhecer este módulo. */
class CatalogoDeTenantsEloquent implements CatalogoDeTenants
{
    public function porId(int $id): ?TenantAtual
    {
        return Tenant::query()->find($id)?->paraTenantAtual();
    }

    public function porCodigo(string $codigo): ?TenantAtual
    {
        return Tenant::query()->where('codigo', strtoupper(trim($codigo)))->first()?->paraTenantAtual();
    }

    public function todos(): array
    {
        return Tenant::query()->orderBy('id')->get()
            ->map(fn (Tenant $tenant) => $tenant->paraTenantAtual())
            ->all();
    }
}
