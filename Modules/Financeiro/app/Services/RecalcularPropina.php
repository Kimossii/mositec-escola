<?php

namespace Modules\Financeiro\Services;

use LogicException;
use Modules\Estabelecimento\Services\RelogioDoTenant;
use Modules\Financeiro\Enums\EstadoCobranca;
use Modules\Financeiro\Models\Propina;
use Modules\Financeiro\Support\BloqueioDePropinas;
use Modules\Financeiro\Support\Dinheiro;
use Modules\Financeiro\Support\FontesDePagamentoDePropina;

/**
 * ÚNICO escritor de `valor_pago`, de `estado` entre Em Aberto / Parcialmente Paga / Paga e de
 * `capital_liquidado_em` (spec Propinas §6). Corre dentro da transacção do chamador, com a propina
 * bloqueada; qualquer falha lança e o chamador reverte tudo (nunca se apanha a excepção lá dentro).
 * Devolve a instância relida e gravada: a que o chamador passou fica desactualizada.
 */
class RecalcularPropina
{
    public function __construct(
        private BloqueioDePropinas $bloqueio,
        private FontesDePagamentoDePropina $fontes,
        private RelogioDoTenant $relogio,
    ) {
    }

    public function executar(Propina $propina): Propina
    {
        $bloqueada = $this->bloqueio->bloquear([(int) $propina->getKey()])->first()
            ?? throw new LogicException('A propina a recalcular não existe no tenant corrente.');

        return $this->recalcular($bloqueada);
    }

    /**
     * Bloqueia TODAS as propinas por id crescente antes de recalcular qualquer uma (sem deadlocks entre
     * operações concorrentes). Devolve as propinas relidas, por id crescente.
     *
     * @param  list<int>  $ids
     * @return list<Propina>
     */
    public function executarVarias(array $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        $bloqueadas = $this->bloqueio->bloquear($ids);

        if ($bloqueadas->count() !== count($ids)) {
            throw new LogicException('Há propinas a recalcular que não existem no tenant corrente.');
        }

        return $bloqueadas->map(fn (Propina $bloqueada) => $this->recalcular($bloqueada))->values()->all();
    }

    private function recalcular(Propina $bloqueada): Propina
    {
        $contribuicoes = $this->fontes->contribuicoes($bloqueada);

        if (in_array($bloqueada->estado, [EstadoCobranca::CANCELADA, EstadoCobranca::ANULADA], true)) {
            if ($contribuicoes !== []) {
                throw new LogicException('Uma propina cancelada ou anulada não pode ter pagamentos activos.');
            }

            return $bloqueada;
        }

        $valor = $bloqueada->valor->unidadesMenores();
        $pago = 0;
        $liquidadaEm = null;

        $hoje = $this->relogio->hoje()->toDateString();

        foreach ($contribuicoes as $contribuicao) {
            if ($contribuicao->data->toDateString() > $hoje) {
                throw new LogicException("Contribuição com data futura na propina {$bloqueada->id}.");
            }

            $pago += $contribuicao->valor->unidadesMenores();

            if ($liquidadaEm === null && $pago >= $valor) {
                $liquidadaEm = $contribuicao->data;
            }
        }

        if ($pago > $valor) {
            throw new LogicException("O valor pago ({$pago}) excede o valor da propina ({$valor}) na propina {$bloqueada->id}.");
        }

        $novoEstado = match (true) {
            $pago === 0 => EstadoCobranca::EM_ABERTO,
            $pago === $valor => EstadoCobranca::PAGA,
            default => EstadoCobranca::PARCIALMENTE_PAGA,
        };

        if ($novoEstado !== $bloqueada->estado && ! $bloqueada->estado->podeTransitarPara($novoEstado)) {
            throw new LogicException("Transição inválida de {$bloqueada->estado->label()} para {$novoEstado->label()}.");
        }

        $bloqueada->forceFill([
            'valor_pago' => Dinheiro::deUnidadesMenores($pago),
            'estado' => $novoEstado,
            'capital_liquidado_em' => $pago === $valor ? $liquidadaEm : null,
        ])->save();

        // F5: a multa da propina (se existir) é recalculada aqui, na mesma transacção.

        return $bloqueada;
    }
}
