<?php

namespace Modules\Docente\DTO;

readonly class SubstituicaoTitularDTO
{
    public function __construct(
        public int $turma_id,
        public int $disciplina_id,
        public int $novo_docente_id,
        public string $ultimo_dia_do_titular,
        public string $motivo,
    ) {
    }
}
