<?php

namespace Modules\Matricula\DTO;

/** O pedido de trancar uma matrícula activa. */
readonly class TrancarMatriculaDTO
{
    public function __construct(
        public string $dataEfeito,
        public string $motivo,
        public bool $confirmarComDividas = false,
        public ?int $utilizadorId = null,
    ) {
    }
}
