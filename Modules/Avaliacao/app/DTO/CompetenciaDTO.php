<?php

namespace Modules\Avaliacao\DTO;

/** Uma competência de uma área. Com `id`, já existe e mantém-se (os registos feitos continuam ligados a ela). */
readonly class CompetenciaDTO
{
    public function __construct(
        public string $descricao,
        public bool $ativa = true,
        public ?int $id = null,
    ) {
    }
}
