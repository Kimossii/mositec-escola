<?php

namespace Modules\Financeiro\Support;

use Modules\Core\Tenancy\TenantContext;

/** Qualquer escrita neste model invalida o cache dos totais financeiros da escola. */
trait InvalidaTotaisFinanceiros
{
    protected static function bootInvalidaTotaisFinanceiros(): void
    {
        $invalidar = function (): void {
            // Sem escola resolvida (ex.: models em bruto nos testes de isolamento) não há cache de escola a invalidar.
            if (app(TenantContext::class)->temTenant()) {
                app(CacheDeTotais::class)->invalidar();
            }
        };

        static::saved($invalidar);
        static::deleted($invalidar);
    }
}
