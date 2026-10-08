<?php

namespace Modules\Core\DTO;

/** Curso e turma actuais já formatados para mostrar (nulos se não houver). Imutável. */
final readonly class SituacaoAcademicaDoAluno
{
    public function __construct(
        public ?string $curso = null,
        public ?string $turma = null,
    ) {}
}
