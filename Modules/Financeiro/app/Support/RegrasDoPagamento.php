<?php

namespace Modules\Financeiro\Support;

use Modules\Financeiro\Models\AcordoPropina;
use Modules\Financeiro\Models\Propina;
use Modules\Financeiro\Models\RegraCobranca;

/**
 * As regras de cobrança a usar ao distribuir um pagamento de UMA matrícula: as da escola, mas com pagamento parcial
 * permitido (em memória, nunca gravado) enquanto a matrícula tiver propinas num acordo de pagamento activo.
 */
class RegrasDoPagamento
{
    public static function paraMatricula(int $matriculaId): RegraCobranca
    {
        $regras = RegraCobranca::doTenant();

        $emAcordo = Propina::query()->where('matricula_id', $matriculaId)->whereIn('id', AcordoPropina::idsEmAcordoActivo())->exists();

        if ($emAcordo) {
            $regras->permite_pagamento_parcial = true;
        }

        return $regras;
    }
}
