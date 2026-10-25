<?php

namespace Modules\PlanoCurricular\Actions;

use Modules\PlanoCurricular\DTO\PlanoCurricularDTO;
use Modules\PlanoCurricular\Models\PlanoCurricular;
use Modules\Core\Traits\LogService;

class AtualizarPlanoCurricularAction
{
    use LogService;

    /** Regista a operação (conclusão ou falha) e delega em `executarSemRegisto()`. */
    public function executar(PlanoCurricular $plano, PlanoCurricularDTO $dto): PlanoCurricular
    {
        return $this->comLog('Atualizar plano curricular', $this->contextoDeArgumentos(func_get_args(), 'executar'), fn () => $this->executarSemRegisto($plano, $dto));
    }

    private function executarSemRegisto(PlanoCurricular $plano, PlanoCurricularDTO $dto): PlanoCurricular
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
