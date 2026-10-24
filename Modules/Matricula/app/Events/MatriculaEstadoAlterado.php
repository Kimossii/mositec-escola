<?php

namespace Modules\Matricula\Events;

use Illuminate\Foundation\Events\Dispatchable;
use InvalidArgumentException;

/**
 * Facto: uma matrícula mudou de estado (ou nasceu: estadoAnterior = null). Só primitivos (fronteira §1.1 do
 * plano de fases): quem escuta relê o que precisar. Despachado DENTRO da transacção de quem muda o estado
 * (não implementa ShouldDispatchAfterCommit): um listener síncrono corre nessa transacção e uma excepção dele
 * reverte a mudança (Q5); quem só deve reagir depois do commit implementa ShouldHandleEventsAfterCommit.
 * Quem escuta tem de ignorar estados que não conhece (ex.: Trancada, plano de trancamento).
 */
final class MatriculaEstadoAlterado
{
    use Dispatchable;

    public function __construct(
        public readonly int $matriculaId,
        public readonly ?int $estadoAnterior,
        public readonly int $estadoNovo,
        /** Y-m-d: data_fim nos estados terminais; data da matrícula na criação; hoje da escola nos restantes. */
        public readonly string $dataEfeito,
        public readonly ?int $utilizadorId,
    ) {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/D', $dataEfeito) !== 1) {
            throw new InvalidArgumentException("A data de efeito tem de estar no formato Y-m-d ({$dataEfeito}).");
        }
    }
}
