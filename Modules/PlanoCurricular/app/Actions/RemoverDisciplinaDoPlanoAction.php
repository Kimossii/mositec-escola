<?php

namespace Modules\PlanoCurricular\Actions;

use Illuminate\Validation\ValidationException;
use Modules\PlanoCurricular\Models\PlanoCurricularDisciplina;
use Modules\Core\Traits\LogService;

class RemoverDisciplinaDoPlanoAction
{
    use LogService;

    /** Regista a operação (conclusão ou falha) e delega em `executarSemRegisto()`. */
    public function executar(PlanoCurricularDisciplina $item): void
    {
        $this->comLog('Remover disciplina do plano', $this->contextoDeArgumentos(func_get_args(), 'executar'), fn () => $this->executarSemRegisto($item));
    }

    private function executarSemRegisto(PlanoCurricularDisciplina $item): void
    {
        if ($item->periodosPorAplicacao()->exists()) {
            throw ValidationException::withMessages([
                'disciplina' => 'Não é possível remover esta disciplina: já tem períodos associados numa aplicação do plano a um ano lectivo.',
            ]);
        }

        $item->delete();
    }
}
