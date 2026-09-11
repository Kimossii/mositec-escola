<?php

namespace Modules\PlanoCurricular\Actions;

use Modules\PlanoCurricular\DTO\PlanoCurricularDisciplinaDTO;
use Modules\PlanoCurricular\Models\PlanoCurricular;
use Modules\PlanoCurricular\Models\PlanoCurricularDisciplina;

class AdicionarDisciplinaAoPlanoAction
{
    public function executar(PlanoCurricular $plano, PlanoCurricularDisciplinaDTO $dto): PlanoCurricularDisciplina
    {
        return PlanoCurricularDisciplina::create([
            'plano_curricular_id' => $plano->id,
            'disciplina_id' => $dto->disciplina_id,
            'nivel_academico_id' => $dto->nivel_academico_id,
            'carga_horaria' => $dto->carga_horaria,
            'creditos' => $dto->creditos,
            'componente' => $dto->componente?->value,
            'tipo' => $dto->tipo->value,
            'obrigatoria' => $dto->obrigatoria,
            'ordem' => $dto->ordem,
        ]);
    }
}
