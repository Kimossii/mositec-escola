<?php

namespace Modules\PlanoCurricular\Actions;

use Modules\PlanoCurricular\DTO\PlanoCurricularDisciplinaDTO;
use Modules\PlanoCurricular\Models\PlanoCurricular;
use Modules\PlanoCurricular\Models\PlanoCurricularDisciplina;
use Modules\Core\Traits\LogService;

class AdicionarDisciplinaAoPlanoAction
{
    use LogService;

    /** Regista a operação (conclusão ou falha) e delega em `executarSemRegisto()`. */
    public function executar(PlanoCurricular $plano, PlanoCurricularDisciplinaDTO $dto): PlanoCurricularDisciplina
    {
        return $this->comLog('Adicionar disciplina ao plano', $this->contextoDeArgumentos(func_get_args(), 'executar'), fn () => $this->executarSemRegisto($plano, $dto));
    }

    private function executarSemRegisto(PlanoCurricular $plano, PlanoCurricularDisciplinaDTO $dto): PlanoCurricularDisciplina
    {
        return PlanoCurricularDisciplina::create([
            'plano_curricular_id' => $plano->id,
            'disciplina_id' => $dto->disciplina_id,
            'carga_horaria' => $dto->carga_horaria,
            'creditos' => $dto->creditos,
            'componente' => $dto->componente?->value,
            'tipo' => $dto->tipo->value,
            'obrigatoria' => $dto->obrigatoria,
            'ordem' => $dto->ordem,
        ]);
    }
}
