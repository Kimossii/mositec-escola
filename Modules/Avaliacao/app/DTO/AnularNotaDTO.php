<?php

namespace Modules\Avaliacao\DTO;

readonly class AnularNotaDTO
{
    public function __construct(
        public int $nota_id,
        public bool $conta_como_zero,
        public string $motivo,
    ) {
    }
}
