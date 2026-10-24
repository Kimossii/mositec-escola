<?php

namespace Modules\Financeiro\Services;

use InvalidArgumentException;
use LogicException;
use Modules\Estabelecimento\Services\RelogioDoTenant;
use Modules\Financeiro\Enums\EstadoCobranca;
use Modules\Financeiro\Models\Propina;
use Modules\Financeiro\Support\BloqueioDePropinas;
use Modules\Financeiro\Support\Dinheiro;
use Modules\Financeiro\Support\FontesDePagamentoDePropina;

/**
 * ÚNICO escritor (spec Propinas §6) de `valor_pago`, de `estado` entre Em Aberto / Parcialmente Paga / Paga, de
 * `capital_liquidado_em` e — desde F4 — do `valor` de uma propina já gerada (`reajustarValor`, spec §14).
 * Corre dentro da transacção do chamador, com a propina bloqueada; qualquer falha lança e o chamador reverte tudo (nunca se apanha a excepção lá dentro).
 * Devolve a instância relida e gravada: a que o chamador passou fica desactualizada.
 */
class RecalcularPropina
{
    public function __construct(
        private BloqueioDePropinas $bloqueio,
        private FontesDePagamentoDePropina $fontes,
        private RelogioDoTenant $relogio,
        private RecalcularMulta $recalcularMulta,
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

    /**
     * Reajusta o valor de UMA propina Em Aberto ou Parcialmente Paga (alteração de preço, F4 / spec §14), na MESMA
     * escrita que o estado, o valor pago e a liquidação: os CHECK de PostgreSQL são verificados por linha, e baixar o
     * valor até ao pago só é coerente se a propina passar a Paga no mesmo UPDATE. `valor = max(pretendido, pago)`:
     * o recebido nunca muda e uma descida abaixo dele fica no pago (Paga, sem devolução). Exige a transacção do chamador.
     */
    public function reajustarValor(Propina $propina, Dinheiro $valorPretendido): Propina
    {
        if ($valorPretendido->unidadesMenores() <= 0) {
            throw new InvalidArgumentException('O valor reajustado de uma propina tem de ser maior que zero.');
        }

        $bloqueada = $this->bloqueio->bloquear([(int) $propina->getKey()])->first()
            ?? throw new LogicException('A propina a reajustar não existe no tenant corrente.');

        if (! in_array($bloqueada->estado, [EstadoCobranca::EM_ABERTO, EstadoCobranca::PARCIALMENTE_PAGA], true)) {
            throw new LogicException("Só se reajusta o valor de uma propina Em Aberto ou Parcialmente Paga (a propina {$bloqueada->id} está {$bloqueada->estado->label()}).");
        }

        return $this->recalcular($bloqueada, $valorPretendido->unidadesMenores());
    }

    /**
     * @param  int|null  $valorPretendido  só no reajuste de preço (F4); null = o valor actual não muda
     */
    private function recalcular(Propina $bloqueada, ?int $valorPretendido = null): Propina
    {
        $contribuicoes = $this->fontes->contribuicoes($bloqueada);

        if (in_array($bloqueada->estado, [EstadoCobranca::CANCELADA, EstadoCobranca::ANULADA], true)) {
            if ($contribuicoes !== []) {
                throw new LogicException('Uma propina cancelada ou anulada não pode ter pagamentos activos.');
            }

            return $bloqueada;
        }

        $hoje = $this->relogio->hoje()->toDateString();
        $pago = 0;

        foreach ($contribuicoes as $contribuicao) {
            if ($contribuicao->data->toDateString() > $hoje) {
                throw new LogicException("Contribuição com data futura na propina {$bloqueada->id}.");
            }

            $pago += $contribuicao->valor->unidadesMenores();
        }

        // F4 (spec §14): o recebido nunca muda com um reajuste de preço. Uma cache desactualizada mudá-lo-ia.
        if ($valorPretendido !== null && $pago !== $bloqueada->valor_pago->unidadesMenores()) {
            throw new LogicException("O valor pago em cache da propina {$bloqueada->id} não coincide com os pagamentos: recalcule-a antes de reajustar o valor.");
        }

        $valor = $valorPretendido === null ? $bloqueada->valor->unidadesMenores() : max($valorPretendido, $pago);

        if ($pago > $valor) {
            throw new LogicException("O valor pago ({$pago}) excede o valor da propina ({$valor}) na propina {$bloqueada->id}.");
        }

        $liquidadaEm = null;
        $acumulado = 0;

        foreach ($contribuicoes as $contribuicao) {
            $acumulado += $contribuicao->valor->unidadesMenores();

            if ($acumulado >= $valor) {
                $liquidadaEm = $contribuicao->data;

                break;
            }
        }

        $novoEstado = match (true) {
            $pago === 0 => EstadoCobranca::EM_ABERTO,
            $pago === $valor => EstadoCobranca::PAGA,
            default => EstadoCobranca::PARCIALMENTE_PAGA,
        };

        if ($novoEstado !== $bloqueada->estado && ! $bloqueada->estado->podeTransitarPara($novoEstado)) {
            throw new LogicException("Transição inválida de {$bloqueada->estado->label()} para {$novoEstado->label()}.");
        }

        // Uma só escrita (CHECK por linha em PostgreSQL). Sem reajuste, `valor` fica igual (não sai como alterado).
        $bloqueada->forceFill([
            'valor' => Dinheiro::deUnidadesMenores($valor),
            'valor_pago' => Dinheiro::deUnidadesMenores($pago),
            'estado' => $novoEstado,
            'capital_liquidado_em' => $pago === $valor ? $liquidadaEm : null,
        ])->save();

        // F5: a multa da propina (se existir) é recalculada aqui, na mesma transacção (spec §6).
        $multa = $bloqueada->multa;

        if ($multa !== null) {
            $this->recalcularMulta->executar($multa);
        }

        return $bloqueada;
    }
}
