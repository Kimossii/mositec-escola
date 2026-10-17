<?php

namespace Modules\Financeiro\Support;

use Modules\Financeiro\Enums\EstadoMulta;
use Modules\Financeiro\Enums\EstadoPagamento;
use Modules\Financeiro\Enums\EstadoUtilizacaoCredito;
use Modules\Financeiro\Models\CreditoUtilizacao;
use Modules\Financeiro\Models\PagamentoMulta;
use Modules\Financeiro\Models\Propina;
use Modules\Financeiro\Models\PropinaMulta;

/**
 * Ponto ÚNICO do que a anulação/cancelamento de uma propina faz à multa dela (spec Propinas §8): com pagamentos
 * de multa Confirmados (ou utilizações de crédito Activas sobre a multa) o cancelamento/anulação é bloqueado até se
 * anularem esses pagamentos; sem pagamentos, a multa passa a ANULADA na mesma transacção.
 */
class AnulacaoDeMultas
{
    /** A multa da propina tem pagamentos Confirmados ou créditos Activos? */
    public function temPagamentos(Propina $propina): bool
    {
        $multa = PropinaMulta::query()->where('propina_id', $propina->getKey())->first();

        return $multa !== null && $this->multaTemPagamentos($multa);
    }

    public function multaTemPagamentos(PropinaMulta $multa): bool
    {
        return PagamentoMulta::query()->where('propina_multa_id', $multa->getKey())
            ->whereHas('pagamento', fn ($pagamento) => $pagamento->where('estado', EstadoPagamento::CONFIRMADO->value))
            ->exists()
            || CreditoUtilizacao::query()->where('propina_multa_id', $multa->getKey())
                ->where('estado', EstadoUtilizacaoCredito::ACTIVA->value)
                ->exists();
    }

    /** Marca ANULADA a multa da propina (sem pagamentos), na transacção do chamador. */
    public function anular(Propina $propina): void
    {
        $multa = PropinaMulta::query()->where('propina_id', $propina->getKey())->first();

        if ($multa !== null && $multa->estado !== EstadoMulta::ANULADA) {
            $multa->forceFill(['estado' => EstadoMulta::ANULADA])->save();
        }
    }
}
