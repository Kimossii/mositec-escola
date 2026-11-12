<?php

namespace Modules\Avaliacao\DTO;

/** A medida tomada para a negativa de um aluno numa disciplina. */
readonly class TratarNegativaDTO
{
    public function __construct(
        public int $inscricao_disciplina_id,
        public string $medida,
    ) {
    }
}
