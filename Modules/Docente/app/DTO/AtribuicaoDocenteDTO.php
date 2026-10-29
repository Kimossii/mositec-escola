<?php

namespace Modules\Docente\DTO;

use Modules\Docente\Enums\PapelDocente;

readonly class AtribuicaoDocenteDTO
{
    public function __construct(
        public int $docente_id,
        public int $turma_id,
        public int $disciplina_id,
        public PapelDocente $papel,
        public string $data_inicio,
        public ?string $data_fim = null,
    ) {
    }
}
