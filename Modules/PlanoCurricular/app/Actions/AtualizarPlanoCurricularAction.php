<?php

namespace Modules\PlanoCurricular\Actions;

use Modules\PlanoCurricular\DTO\PlanoCurricularDTO;
use Modules\PlanoCurricular\Models\PlanoCurricular;

class AtualizarPlanoCurricularAction
{
    public function executar(PlanoCurricular $plano, PlanoCurricularDTO $dto): PlanoCurricular
    {
        $plano->fill([
            'nivel_academico_id' => $dto->nivel_academico_id,
            'curso_id' => $dto->curso_id,
            'codigo' => $dto->codigo,
            'nome' => $dto->nome,
            'descricao' => $dto->descricao,
        ]);
        $plano->save();

        return $plano->fresh();
    }
}
