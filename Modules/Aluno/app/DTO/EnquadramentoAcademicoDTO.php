<?php

namespace Modules\Aluno\DTO;

/** Por onde um aluno é enquadrado academicamente: um curso ou um nível académico (pelo menos um). */
readonly class EnquadramentoAcademicoDTO
{
    public function __construct(
        public ?int $cursoId = null,
        public ?int $nivelAcademicoId = null,
        public ?int $utilizadorId = null,
    ) {
    }
}
