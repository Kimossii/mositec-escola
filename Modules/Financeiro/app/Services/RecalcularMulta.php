<?php

namespace Modules\Financeiro\Services;

use Illuminate\Support\Facades\DB;
use LogicException;
use Modules\Financeiro\Enums\EstadoPagamento;
use Modules\Financeiro\Enums\EstadoUtilizacaoCredito;
use Modules\Financeiro\Models\CreditoUtilizacao;
use Modules\Financeiro\Models\PagamentoMulta;
use Modules\Financeiro\Models\PropinaMulta;
use Modules\Financeiro\Support\Dinheiro;

/**
 * ÚNICO escritor de `valor_pago` de uma multa (spec Propinas §15): soma as distribuições de pagamentos Confirmados
 * sobre a multa e as utilizações de crédito Activas sobre ela. Corre na transacção do chamador, com a multa bloqueada
 * (`lockForUpdate`). Não altera `valor`/`estado`/`bloqueada_automatico` (esses são do comando de aplicação e da
 * isenção manual). Devolve a instância relida e gravada.
 */
class RecalcularMulta
{
    public function executar(PropinaMulta $multa): PropinaMulta
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Recalcular uma multa exige uma transacção aberta do chamador.');
        }

        $bloqueada = PropinaMulta::query()->whereKey($multa->getKey())->lockForUpdate()->first()
            ?? throw new LogicException('A multa a recalcular não existe no tenant corrente.');

        $pago = (int) PagamentoMulta::query()
            ->where('propina_multa_id', $bloqueada->getKey())
            ->whereHas('pagamento', fn ($pagamento) => $pagamento->where('estado', EstadoPagamento::CONFIRMADO->value))
            ->sum('valor');

        $pago += (int) CreditoUtilizacao::query()
            ->where('propina_multa_id', $bloqueada->getKey())
            ->where('estado', EstadoUtilizacaoCredito::ACTIVA->value)
            ->sum('valor');

        if ($pago > $bloqueada->valor->unidadesMenores()) {
            throw new LogicException("O valor pago ({$pago}) excede o valor da multa {$bloqueada->id} ({$bloqueada->valor->unidadesMenores()}).");
        }

        $bloqueada->forceFill(['valor_pago' => Dinheiro::deUnidadesMenores($pago)])->save();

        return $bloqueada;
    }
}
