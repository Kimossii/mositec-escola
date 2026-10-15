<?php

namespace Modules\Financeiro\Support;

use Modules\Financeiro\Contracts\HistoricoDePagamentosDaPropina;
use Modules\Financeiro\Models\CreditoUtilizacao;
use Modules\Financeiro\Models\PagamentoPropina;
use Modules\Financeiro\Models\Propina;

/**
 * "Esta propina alguma vez recebeu dinheiro?" (spec Propinas §8): qualquer distribuição, de um pagamento em qualquer
 * estado, ou qualquer utilização de crédito, Activa ou Anulada. Com histórico, a propina já não se cancela: anula-se.
 */
class HistoricoDosPagamentos implements HistoricoDePagamentosDaPropina
{
    public function temHistorico(Propina $propina): bool
    {
        return PagamentoPropina::query()->where('propina_id', $propina->getKey())->exists()
            || CreditoUtilizacao::query()->where('propina_id', $propina->getKey())->exists();
    }
}
