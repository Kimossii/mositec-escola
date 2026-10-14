<?php

namespace Modules\Financeiro\Tests\Concerns;

use Modules\Financeiro\Contracts\HistoricoDePagamentosDaPropina;
use Modules\Financeiro\Models\Propina;

/**
 * Substituto de Pagamentos nos testes: propinas marcadas à mão como "já tiveram pagamentos".
 */
final class HistoricoPagamentoFalso implements HistoricoDePagamentosDaPropina
{
    /** @var array<int, true> */
    private array $marcadas = [];

    public function marcar(Propina $propina): void
    {
        $this->marcadas[(int) $propina->id] = true;
    }

    public function temHistorico(Propina $propina): bool
    {
        return isset($this->marcadas[(int) $propina->id]);
    }
}
