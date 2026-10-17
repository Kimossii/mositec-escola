<?php

namespace Modules\Financeiro\Support;

use Modules\Financeiro\Contracts\HistoricoDePagamentosDaPropina;
use Modules\Financeiro\Models\CreditoUtilizacao;
use Modules\Financeiro\Models\PagamentoMulta;
use Modules\Financeiro\Models\PagamentoPropina;
use Modules\Financeiro\Models\Propina;
use Modules\Financeiro\Models\PropinaMulta;

/**
 * "Esta propina alguma vez recebeu dinheiro?" (spec Propinas §8): qualquer distribuição, de um pagamento em qualquer
 * estado, ou qualquer utilização de crédito, Activa ou Anulada — sobre a propina OU sobre a multa dela. Com histórico,
 * a propina já não se cancela: anula-se.
 */
class HistoricoDosPagamentos implements HistoricoDePagamentosDaPropina
{
    public function temHistorico(Propina $propina): bool
    {
        if (PagamentoPropina::query()->where('propina_id', $propina->getKey())->exists()
            || CreditoUtilizacao::query()->where('propina_id', $propina->getKey())->exists()) {
            return true;
        }

        $multa = PropinaMulta::query()->where('propina_id', $propina->getKey())->first();

        if ($multa === null) {
            return false;
        }

        return PagamentoMulta::query()->where('propina_multa_id', $multa->id)->exists()
            || CreditoUtilizacao::query()->where('propina_multa_id', $multa->id)->exists();
    }
}
