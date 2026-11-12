<?php

namespace Modules\Avaliacao\DTO;

use Modules\Avaliacao\Enums\Decisao;

/** A decisão de progressão de uma criança da turma, quando a regra da classe é «decidida criança a criança». */
readonly class DecidirProgressaoDTO
{
    public function __construct(
        public int $turma_id,
        public int $matricula_id,
        public Decisao $decisao,
        public ?string $motivo = null,
    ) {
    }
}
