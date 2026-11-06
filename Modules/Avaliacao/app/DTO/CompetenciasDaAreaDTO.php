<?php

namespace Modules\Avaliacao\DTO;

/** As competências de uma área (disciplina) para uma classe de Creche ou Pré-Escolar, ou para todas (`nivel_academico_id` nulo). */
readonly class CompetenciasDaAreaDTO
{
    /** @param  list<CompetenciaDTO>  $competencias  pela ordem em que ficam */
    public function __construct(
        public int $disciplina_id,
        public ?int $nivel_academico_id,
        public array $competencias,
    ) {
    }
}
