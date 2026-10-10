<?php

namespace Modules\Financeiro\Tests\Concerns;

use Carbon\CarbonImmutable;
use Modules\Financeiro\Contracts\FonteDePagamentoDePropina;
use Modules\Financeiro\Models\Propina;
use Modules\Financeiro\Support\ContribuicaoDePagamento;
use Modules\Financeiro\Support\Dinheiro;

/**
 * Substituto de Pagamentos nos testes: contribuições definidas à mão por propina.
 */
final class FontePagamentoFalsa implements FonteDePagamentoDePropina
{
    /** @var array<int, list<ContribuicaoDePagamento>> */
    private array $porPropina = [];

    /**
     * @param  list<array{0: int, 1: string}>  $contribuicoes  [unidades menores, data Y-m-d]
     */
    public function definir(Propina $propina, array $contribuicoes): void
    {
        $this->porPropina[$propina->id] = array_map(
            fn (array $c) => new ContribuicaoDePagamento(Dinheiro::deUnidadesMenores($c[0]), CarbonImmutable::parse($c[1])),
            $contribuicoes,
        );
    }

    public function contribuicoes(Propina $propina): array
    {
        return $this->porPropina[$propina->id] ?? [];
    }
}
