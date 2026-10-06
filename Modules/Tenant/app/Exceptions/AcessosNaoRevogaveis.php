<?php

namespace Modules\Tenant\Exceptions;

use Modules\Core\Tenancy\Enums\EstadoTenant;

/** Revogar acessos fora do momento de suspender só se faz a escolas Suspensas. */
class AcessosNaoRevogaveis extends OperacaoDeTenantRecusada
{
    public static function para(string $codigo, EstadoTenant $actual): self
    {
        return new self("Só se revogam acessos de uma escola Suspensa (tenant {$codigo}: estado actual {$actual->label()}). Suspenda a escola primeiro; ao suspender também se pode pedir a revogação.");
    }
}
