<?php

namespace Modules\Avaliacao\DTO;

readonly class LancarNotaDTO
{
    public function __construct(
        public int $instrumento_id,
        public int $inscricao_disciplina_id,
        public string $valor,
        public ?string $motivo = null,
    ) {
    }
}
