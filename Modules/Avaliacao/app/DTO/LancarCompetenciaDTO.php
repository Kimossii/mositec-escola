<?php

namespace Modules\Avaliacao\DTO;

readonly class LancarCompetenciaDTO
{
    public function __construct(
        public int $inscricao_disciplina_id,
        public int $competencia_id,
        public int $periodo_id,
        public int $escala_nivel_id,
        public ?string $motivo = null,
    ) {
    }
}
