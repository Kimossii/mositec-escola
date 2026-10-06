<?php

namespace Modules\Tenant\Services;

use Illuminate\Http\Request;
use Modules\Core\Tenancy\Contracts\ResolvedorTenant;
use Modules\Core\Tenancy\Support\NormalizadorHost;
use Modules\Core\Tenancy\TenantAtual;
use Modules\Tenant\Models\Domain;

class ResolvedorTenantPorDominio implements ResolvedorTenant
{
    public function resolver(Request $request): ?TenantAtual
    {
        $host = NormalizadorHost::normalizar($request->getHost());

        $dominio = Domain::query()->with('tenant')->where('dominio', $host)->first();

        return $dominio?->tenant->paraTenantAtual();
    }
}
