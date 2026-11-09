<?php

namespace Modules\Matricula\DTO;

use Modules\Matricula\Enums\EstadoMatriculaEnum;

/** Uma linha do histórico de estados de uma matrícula. */
readonly class HistoricoMatriculaDTO
{
    public function __construct(
        public ?EstadoMatriculaEnum $estadoAnterior,
        public EstadoMatriculaEnum $estadoNovo,
        public ?int $utilizadorId = null,
        public ?string $dataEfeito = null,
        public ?string $motivo = null,
        public ?array $detalhe = null,
    ) {
    }
}
