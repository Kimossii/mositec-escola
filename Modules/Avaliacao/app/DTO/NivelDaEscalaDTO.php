<?php

namespace Modules\Avaliacao\DTO;

/** Um nível de uma escala qualitativa. Com `id`, é um nível que já existe e mantém-se. */
readonly class NivelDaEscalaDTO
{
    public function __construct(
        public string $codigo,
        public string $designacao,
        public ?int $id = null,
    ) {
    }
}
