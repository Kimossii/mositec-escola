<?php

namespace Modules\PlanoCurricular\Actions;

use Modules\PlanoCurricular\DTO\PlanoCurricularDisciplinaDTO;
use Modules\PlanoCurricular\Models\PlanoCurricularDisciplina;

class AtualizarDisciplinaDoPlanoAction
{
    public function executar(PlanoCurricularDisciplina $item, PlanoCurricularDisciplinaDTO $dto): PlanoCurricularDisciplina
    {
        $item->fill([
            'disciplina_id' => $dto->disciplina_id,
            'carga_horaria' => $dto->carga_horaria,
            'creditos' => $dto->creditos,
            'componente' => $dto->componente?->value,
            'tipo' => $dto->tipo->value,
            'obrigatoria' => $dto->obrigatoria,
            'ordem' => $dto->ordem,
        ]);
        $item->save();

        return $item->fresh();
    }
}
