<?php

namespace Modules\Matricula\DTO;

/** O pedido de reactivar uma matrícula trancada. */
readonly class ReactivarMatriculaDTO
{
    public function __construct(
        public string $dataEfeito,
        public string $motivo,
        public ?int $utilizadorId = null,
    ) {
    }
}
