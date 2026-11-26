<?php

namespace Modules\Tenant\Services;

use Modules\Core\Tenancy\Contracts\EnderecoDoTenant;
use Modules\Tenant\Models\Domain;

/** Implementação do contrato do Core: o Core gera URLs do tenant sem conhecer os models deste módulo. */
class EnderecoDoTenantEloquent implements EnderecoDoTenant
{
    public function dominioPrincipal(int $tenantId): ?string
    {
        return Domain::query()
            ->where('tenant_id', $tenantId)
            ->where('is_principal', true)
            ->value('dominio');
    }
}
