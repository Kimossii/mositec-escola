<?php

namespace Modules\Avaliacao\DTO;

use Modules\Avaliacao\Enums\Progressao;

/** A regra de uma classe qualitativa (Creche ou Pré-Escolar) num ano lectivo: a escala e como se decide a progressão. */
readonly class RegraQualitativaDTO
{
    public function __construct(
        public int $ano_lectivo_id,
        public int $nivel_academico_id,
        public int $escala_id,
        public Progressao $progressao = Progressao::AUTOMATICA,
    ) {
    }
}
