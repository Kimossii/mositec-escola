<?php

namespace Modules\Avaliacao\DTO;

readonly class LancarQualitativaDTO
{
    public function __construct(
        public int $inscricao_disciplina_id,
        public int $periodo_id,
        public int $escala_nivel_id,
        public ?string $observacao = null,
        public ?string $motivo = null,
    ) {
    }
}
