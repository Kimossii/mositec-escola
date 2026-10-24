<?php

namespace Modules\Financeiro\Support;

use Carbon\CarbonImmutable;

/**
 * Tudo o que uma execução da geração lê uma vez: a memória do resolvedor, o "hoje" da escola
 * (RelogioDoTenant, §8.2b), as regras de cobrança a copiar para o snapshot e a moeda/câmbio do dia.
 * Criado de novo em cada execução e em cada tenant.
 */
final class ContextoDeGeracao
{
    public function __construct(
        public readonly ResolucaoDePlanosMemorizada $resolucao,
        public readonly CarbonImmutable $hoje,
        public readonly int $dia_vencimento,
        public readonly int $dias_tolerancia,
        public readonly string $moeda,
        public readonly ?int $cambio_usd,
    ) {
    }
}
