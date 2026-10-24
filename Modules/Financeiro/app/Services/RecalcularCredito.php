<?php

namespace Modules\Financeiro\Services;

use Illuminate\Support\Facades\DB;
use LogicException;
use Modules\Financeiro\Enums\EstadoCredito;
use Modules\Financeiro\Enums\EstadoUtilizacaoCredito;
use Modules\Financeiro\Models\Credito;
use Modules\Financeiro\Models\CreditoUtilizacao;
use Modules\Financeiro\Support\Dinheiro;

/**
 * ÚNICO escritor de `valor_utilizado` e do estado de um crédito (EscritoresDePagamentoTest). Corre na transacção do
 * chamador, com o crédito bloqueado (relê-o com FOR UPDATE). Devolve a instância relida e gravada.
 */
class RecalcularCredito
{
    public function executar(Credito $credito): Credito
    {
        $bloqueado = $this->bloquear($credito);
        $usado = $this->usado($bloqueado);

        if ($bloqueado->estado === EstadoCredito::ANULADO) {
            if ($usado !== 0) {
                throw new LogicException("O crédito {$bloqueado->id} está anulado e tem utilizações activas.");
            }

            return $bloqueado;
        }

        $valor = $bloqueado->valor->unidadesMenores();

        if ($usado > $valor) {
            throw new LogicException("As utilizações ({$usado}) excedem o crédito {$bloqueado->id} ({$valor}).");
        }

        $bloqueado->forceFill([
            'valor_utilizado' => Dinheiro::deUnidadesMenores($usado),
            'estado' => $usado === $valor ? EstadoCredito::ESGOTADO : EstadoCredito::DISPONIVEL,
        ])->save();

        return $bloqueado;
    }

    /** Acompanha a anulação do pagamento de origem: só um crédito sem utilizações activas. */
    public function anular(Credito $credito): Credito
    {
        $bloqueado = $this->bloquear($credito);

        if ($this->usado($bloqueado) !== 0) {
            throw new LogicException("O crédito {$bloqueado->id} tem utilizações activas: anule-as primeiro.");
        }

        $bloqueado->forceFill(['valor_utilizado' => Dinheiro::deUnidadesMenores(0), 'estado' => EstadoCredito::ANULADO])->save();

        return $bloqueado;
    }

    private function bloquear(Credito $credito): Credito
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Recalcular um crédito exige uma transacção aberta do chamador.');
        }

        return Credito::query()->whereKey($credito->getKey())->lockForUpdate()->first()
            ?? throw new LogicException('O crédito a recalcular não existe no tenant corrente.');
    }

    private function usado(Credito $credito): int
    {
        return (int) CreditoUtilizacao::query()
            ->where('credito_id', $credito->getKey())
            ->where('estado', EstadoUtilizacaoCredito::ACTIVA->value)
            ->sum('valor');
    }
}
