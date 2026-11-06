<?php

namespace Modules\Financeiro\DTO;

use Modules\Financeiro\Enums\OrigemCobranca;

/** Os dados de uma cobrança de produtos e serviços; a matrícula vai à parte, como nas outras Actions do módulo. */
final class CriarCobrancaDTO
{
    /** @param  list<array{tipo: string, id: int, quantidade: int, variante_id?: ?int}>  $itens */
    public function __construct(
        public readonly array $itens,
        public readonly ?string $dataLimite = null,
        public readonly ?string $observacao = null,
        public readonly OrigemCobranca $origem = OrigemCobranca::MANUAL,
        public readonly ?int $utilizadorId = null,
    ) {
    }
}
