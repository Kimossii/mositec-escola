<?php

namespace Modules\Core\Tenancy;

use Modules\Core\Tenancy\Exceptions\AlteracaoDeTenantProibida;

trait PertenceAoTenant
{
    protected static function bootPertenceAoTenant(): void
    {
        static::addGlobalScope(new TenantScope());

        static::creating(function ($model) {
            $tenantId = app(TenantContext::class)->id();

            if ($model->tenant_id !== null && (int) $model->tenant_id !== $tenantId) {
                throw new AlteracaoDeTenantProibida($model::class);
            }

            $model->tenant_id = $tenantId;
        });

        // save() e delete() de uma instância não passam pelo global scope:
        // sem esta verificação, um registo carregado num tenant podia ser
        // alterado ou apagado sem contexto, ou a partir de outro tenant.
        $exigirDono = function ($model) {
            $tenantId = app(TenantContext::class)->id();

            if ($model->isDirty('tenant_id') || (int) $model->getOriginal('tenant_id') !== $tenantId) {
                throw new AlteracaoDeTenantProibida($model::class);
            }
        };

        static::updating($exigirDono);
        static::deleting($exigirDono);
        static::registerModelEvent('restoring', $exigirDono);
    }
}
