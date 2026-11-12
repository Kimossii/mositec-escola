<?php

namespace Modules\Financeiro\DTO;

/** Os dados de um acordo de pagamento em prestações; a matrícula vai à parte, como nas outras Actions do módulo. */
final class CriarAcordoDTO
{
    /** @param  list<int>  $propinaIds */
    public function __construct(
        public readonly array $propinaIds,
        public readonly int $descontoMultaPct,
        public readonly int $prestacoes,
        public readonly string $primeiroVencimento,
        public readonly string $motivo,
        public readonly ?int $utilizadorId = null,
    ) {
    }
}
