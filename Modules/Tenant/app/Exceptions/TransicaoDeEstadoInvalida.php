<?php

namespace Modules\Tenant\Exceptions;

use Modules\Core\Tenancy\Enums\EstadoTenant;

class TransicaoDeEstadoInvalida extends OperacaoDeTenantRecusada
{
    public static function para(string $codigo, EstadoTenant $actual, string $accao): self
    {
        $detalhe = $actual === EstadoTenant::ENCERRADO
            ? ' O estado Encerrado é terminal; reabrir é um procedimento manual.'
            : '';

        return new self("Não é possível {$accao} (tenant {$codigo}): estado actual {$actual->label()}.{$detalhe}");
    }
}
