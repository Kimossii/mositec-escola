<?php

namespace Modules\Avaliacao\DTO;

/** A nota do exame próprio (NE) ou do exame de recurso (NER) de um aluno; o exame cria-se na primeira nota. */
readonly class LancarExameDTO
{
    public function __construct(
        public int $turma_id,
        public int $disciplina_id,
        public int $inscricao_disciplina_id,
        public string $valor,
        public ?string $motivo = null,
    ) {
    }
}
