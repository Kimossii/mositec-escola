<?php

namespace Modules\PlanoCurricular\Actions;

use Modules\PlanoCurricular\Models\PlanoCurricularDisciplina;

class RemoverDisciplinaDoPlanoAction
{
    public function executar(PlanoCurricularDisciplina $item): void
    {
        $item->delete();
    }
}
