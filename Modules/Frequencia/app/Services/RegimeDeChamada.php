<?php

namespace Modules\Frequencia\Services;

use Modules\Estabelecimento\Enums\EtapaEnsinoEnum;
use Modules\Frequencia\Enums\TipoAula;
use Modules\Turma\Models\Turma;

/** Como se faz a chamada de uma turma: uma por dia no Pré-Escolar e no Primário (professor único), por tempo nas outras etapas. */
class RegimeDeChamada
{
    public function daTurma(Turma $turma): TipoAula
    {
        $etapa = $turma->nivelAcademico?->etapa_ensino;

        return in_array($etapa, [EtapaEnsinoEnum::PRE_ESCOLAR, EtapaEnsinoEnum::PRIMARIO], true) ? TipoAula::DIA : TipoAula::TEMPO;
    }
}
