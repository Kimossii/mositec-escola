<?php

namespace Modules\Financeiro\Contracts;

use Carbon\CarbonInterface;
use Modules\Financeiro\Enums\EstadoCobranca;
use Modules\Financeiro\Support\Dinheiro;

/**
 * O mínimo que EstadoCobranca::resolver() precisa de saber de uma cobrança (hoje, Propina).
 */
interface CobrancaResolvivel
{
    /** Um dos cinco estados persistidos. */
    public function estadoPersistido(): EstadoCobranca;

    public function valorDevido(): Dinheiro;

    public function valorRecebido(): Dinheiro;

    /** Primeiro dia do período coberto. */
    public function inicioDoPeriodo(): CarbonInterface;

    /** Último dia sem atraso: vencimento + dias de tolerância (snapshot). */
    public function limiteSemAtraso(): CarbonInterface;
}
