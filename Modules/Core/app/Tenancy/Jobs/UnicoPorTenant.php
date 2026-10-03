<?php

namespace Modules\Core\Tenancy\Jobs;

use Modules\Core\Tenancy\TenantContext;

/**
 * Para jobs ShouldBeUnique com ComTenant: o lock do Laravel não inclui o tenant, por isso duas
 * escolas bloqueavam-se uma à outra. Esta trait fornece o uniqueId() com o prefixo
 * `tenant:{id}:`. O teste de arquitectura exige-a a todo o ShouldBeUnique com ComTenant.
 *
 * Para distinguir instâncias dentro do mesmo tenant, defina `identificadorUnico(): string`
 * na classe do job (não defina uniqueId(): sobrepor-se-ia a esta trait).
 *
 * O uniqueId() precisa de contexto: no despacho e na execução existe sempre (ver ComTenant).
 */
trait UnicoPorTenant
{
    public function uniqueId(): string
    {
        $base = method_exists($this, 'identificadorUnico') ? (string) $this->identificadorUnico() : '';

        return 'tenant:' . app(TenantContext::class)->id() . ':' . $base;
    }
}
