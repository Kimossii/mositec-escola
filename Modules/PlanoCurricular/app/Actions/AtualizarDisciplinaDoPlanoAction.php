<?php

namespace Modules\PlanoCurricular\Actions;

use Modules\PlanoCurricular\DTO\PlanoCurricularDisciplinaDTO;
use Modules\PlanoCurricular\Models\PlanoCurricularDisciplina;
use Modules\Core\Traits\LogService;

class AtualizarDisciplinaDoPlanoAction
{
    use LogService;

    /** Regista a operação (conclusão ou falha) e delega em `executarSemRegisto()`. */
    public function executar(PlanoCurricularDisciplina $item, PlanoCurricularDisciplinaDTO $dto): PlanoCurricularDisciplina
    {
        return $this->comLog('Atualizar disciplina do plano', $this->contextoDeArgumentos(func_get_args(), 'executar'), fn () => $this->executarSemRegisto($item, $dto));
    }

    private function executarSemRegisto(PlanoCurricularDisciplina $item, PlanoCurricularDisciplinaDTO $dto): PlanoCurricularDisciplina
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
