<?php

namespace Modules\Financeiro\Support;

/**
 * O câmbio escolhido para uma data: a taxa, a data da linha usada (null quando a moeda é o
 * próprio USD) e a origem: 'usd', 'escola' (modo manual) ou 'plataforma' (padrão).
 */
final class CambioResolvido
{
    public function __construct(
        public readonly TaxaCambio $taxa,
        public readonly ?string $data,
        public readonly string $origem,
    ) {
    }
}
