<?php

namespace Modules\Avaliacao\DTO;

/** Uma escala qualitativa com os seus níveis, do mais baixo para o mais alto. Com `id`, actualiza a escala. */
readonly class EscalaQualitativaDTO
{
    /** @param  list<NivelDaEscalaDTO>  $niveis */
    public function __construct(
        public string $nome,
        public array $niveis,
        public ?int $id = null,
    ) {
    }
}
