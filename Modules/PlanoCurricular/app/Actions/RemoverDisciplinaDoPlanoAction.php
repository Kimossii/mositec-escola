<?php

namespace Modules\PlanoCurricular\Actions;

use Illuminate\Validation\ValidationException;
use Modules\PlanoCurricular\Models\PlanoCurricularDisciplina;

class RemoverDisciplinaDoPlanoAction
{
    public function executar(PlanoCurricularDisciplina $item): void
    {
        if ($item->periodosPorAplicacao()->exists()) {
            throw ValidationException::withMessages([
                'disciplina' => 'Não é possível remover esta disciplina: já tem períodos associados numa aplicação do plano a um ano lectivo.',
            ]);
        }

        $item->delete();
    }
}
