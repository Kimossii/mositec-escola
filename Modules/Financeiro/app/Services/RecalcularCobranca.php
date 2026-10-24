<?php

namespace Modules\Financeiro\Services;

use Illuminate\Support\Facades\DB;
use LogicException;
use Modules\Financeiro\Support\Dinheiro;
use Modules\Financeiro\Enums\EstadoCobranca;
use Modules\Financeiro\Enums\EstadoPagamento;
use Modules\Financeiro\Models\Cobranca;
use Modules\Financeiro\Models\PagamentoCobranca;

/**
 * ÚNICO escritor de `valor_pago` e do estado persistido de uma cobrança (Em Aberto, Parcialmente Paga, Paga):
 * soma as linhas de pagamentos Confirmados. Cancelada e Anulada são terminais e nunca se recalculam.
 */
class RecalcularCobranca
{
    /** @param list<int> $ids */
    public function executarVarias(array $ids): void
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Recalcular cobranças exige uma transacção aberta.');
        }

        $ids = array_values(array_unique(array_map('intval', $ids)));
        sort($ids);

        foreach (Cobranca::query()->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get() as $cobranca) {
            if (in_array($cobranca->estado, [EstadoCobranca::CANCELADA, EstadoCobranca::ANULADA], true)) {
                continue;
            }

            $pago = (int) PagamentoCobranca::query()
                ->where('cobranca_id', $cobranca->id)
                ->whereHas('pagamento', fn ($p) => $p->where('estado', EstadoPagamento::CONFIRMADO->value))
                ->sum('valor');
            $total = $cobranca->total->unidadesMenores();
            $estado = $pago <= 0 ? EstadoCobranca::EM_ABERTO : ($pago >= $total ? EstadoCobranca::PAGA : EstadoCobranca::PARCIALMENTE_PAGA);

            $cobranca->forceFill(['valor_pago' => Dinheiro::deUnidadesMenores($pago), 'estado' => $estado])->save();
        }
    }
}
