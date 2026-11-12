<?php

namespace Modules\Avaliacao\DTO;

use Modules\Avaliacao\Enums\FasePauta;

/** Reabertura de uma pauta final: a turma, a fase (1.ª fase ou recurso) e o motivo. */
readonly class ReabrirPautaDTO
{
    public function __construct(
        public int $turma_id,
        public string $motivo,
        public FasePauta $fase = FasePauta::EPOCA_NORMAL,
    ) {
    }
}
