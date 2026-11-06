<?php

namespace Modules\Avaliacao\DTO;

/** Uma excepção ao prazo normal para um caso concreto (uma turma, uma disciplina e/ou um docente; nulos = todos). */
readonly class ProlongarPrazoDTO
{
    public function __construct(
        public int $prazo_id,
        public string $data_limite,
        public string $motivo,
        public ?int $turma_id = null,
        public ?int $disciplina_id = null,
        public ?int $docente_id = null,
    ) {
    }
}
