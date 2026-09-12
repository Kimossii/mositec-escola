<?php

namespace Modules\PlanoCurricular\Actions;

use Modules\Estabelecimento\Models\Estabelecimento;
use Modules\PlanoCurricular\DTO\PlanoCurricularDTO;
use Modules\PlanoCurricular\Models\PlanoCurricular;

class CriarPlanoCurricularAction
{
    public function executar(PlanoCurricularDTO $dto): PlanoCurricular
    {
        return PlanoCurricular::create([
            'estabelecimento_id' => Estabelecimento::current()?->id,
            'nivel_academico_id' => $dto->nivel_academico_id,
            'curso_id' => $dto->curso_id,
            'codigo' => $dto->codigo,
            'nome' => $dto->nome,
            'descricao' => $dto->descricao,
        ]);
    }
}
