<?php

namespace Modules\Avaliacao\Services;

use Modules\Avaliacao\Models\AvaliacaoRegra;
use Modules\Turma\Models\Turma;

class RegraAvaliacaoService
{
    /** A regra do ano lectivo e da classe da turma, ou `null` se a escola ainda não a configurou. */
    public function paraTurma(Turma $turma): ?AvaliacaoRegra
    {
        return AvaliacaoRegra::query()
            ->where('ano_lectivo_id', $turma->ano_lectivo_id)
            ->where('nivel_academico_id', $turma->nivel_academico_id)
            ->first();
    }
}
