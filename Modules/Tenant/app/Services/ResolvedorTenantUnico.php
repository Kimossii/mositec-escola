<?php

namespace Modules\Tenant\Services;

use Illuminate\Http\Request;
use Modules\Core\Tenancy\Contracts\ResolvedorTenant;
use Modules\Core\Tenancy\TenantAtual;
use Modules\Tenant\Exceptions\InstalacaoUnicaInvalida;
use Modules\Tenant\Models\Tenant;

/**
 * Instalação local ou dedicada: acedida por IP ou nome de rede, por isso o host é ignorado.
 */
class ResolvedorTenantUnico implements ResolvedorTenant
{
    public function resolver(Request $request): ?TenantAtual
    {
        $tenants = Tenant::query()->limit(2)->get();

        if ($tenants->count() !== 1) {
            throw new InstalacaoUnicaInvalida(Tenant::query()->count());
        }

        return $tenants->first()->paraTenantAtual();
    }
}
