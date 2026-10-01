<?php

namespace Modules\Tenant\Services;

use Modules\Tenant\Exceptions\TenantNaoEncontrado;
use Modules\Tenant\Models\Tenant;

class TenantConsultaService
{
    /**
     * @throws TenantNaoEncontrado
     */
    public function porCodigo(string $codigo): Tenant
    {
        return Tenant::query()->where('codigo', trim($codigo))->first()
            ?? throw new TenantNaoEncontrado(trim($codigo));
    }
}
