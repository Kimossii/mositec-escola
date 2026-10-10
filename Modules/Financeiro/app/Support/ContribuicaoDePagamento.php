<?php

namespace Modules\Financeiro\Support;

use Carbon\CarbonImmutable;
use InvalidArgumentException;
use Modules\Estabelecimento\Services\RelogioDoTenant;

/**
 * Uma parcela que conta para o valor pago de uma propina, com a data efectiva do pagamento (ou da
 * utilização de crédito). A data decide `capital_liquidado_em` e, em F5, o saldo numa data.
 */
final class ContribuicaoDePagamento
{
    public function __construct(
        public readonly Dinheiro $valor,
        public readonly CarbonImmutable $data,
    ) {
        if ($valor->unidadesMenores() <= 0) {
            throw new InvalidArgumentException('Uma contribuição para o valor pago tem de ser maior que zero.');
        }

        if ($data->toDateString() > app(RelogioDoTenant::class)->hoje()->toDateString()) {
            throw new InvalidArgumentException('A data de uma contribuição para o valor pago não pode estar no futuro.');
        }
    }
}
