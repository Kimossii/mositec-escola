<?php

namespace Modules\PlanoCurricular\Actions;

use Modules\Estabelecimento\Models\Estabelecimento;
use Modules\PlanoCurricular\DTO\PlanoCurricularDTO;
use Modules\PlanoCurricular\Models\PlanoCurricular;
use Modules\Core\Traits\LogService;

class CriarPlanoCurricularAction
{
    use LogService;

    /** Regista a operação (conclusão ou falha) e delega em `executarSemRegisto()`. */
    public function executar(PlanoCurricularDTO $dto): PlanoCurricular
    {
        return $this->comLog('Criar plano curricular', $this->contextoDeArgumentos(func_get_args(), 'executar'), fn () => $this->executarSemRegisto($dto));
    }

    private function executarSemRegisto(PlanoCurricularDTO $dto): PlanoCurricular
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
