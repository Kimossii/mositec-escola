<?php

namespace Modules\Core\Tenancy\Contracts;

use Illuminate\Http\Request;
use Modules\Core\Tenancy\TenantAtual;

interface ResolvedorTenant
{
    /**
     * Devolve o tenant a que o pedido se destina, ou null se não corresponder a nenhum.
     * Não avalia o estado do tenant: isso é responsabilidade do middleware.
     */
    public function resolver(Request $request): ?TenantAtual;
}
