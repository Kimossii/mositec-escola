<?php

namespace Modules\PlanoCurricular\Actions;

use Illuminate\Support\Collection;
use Modules\PlanoCurricular\DTO\DefinirPeriodosDisciplinaDTO;
use Modules\PlanoCurricular\Models\PlanoCurricularAnoLectivo;
use Modules\PlanoCurricular\Models\PlanoCurricularDisciplina;

class DefinirPeriodosDaDisciplinaAction
{
    public function executar(PlanoCurricularAnoLectivo $aplicacao, PlanoCurricularDisciplina $disciplina, DefinirPeriodosDisciplinaDTO $dto): Collection
    {
        $disciplina->periodosPorAplicacao()
            ->where('plano_curricular_ano_lectivo_id', $aplicacao->id)
            ->delete();

        return collect($dto->periodo_ids)->map(
            fn (int $periodoId) => $disciplina->periodosPorAplicacao()->create([
                'plano_curricular_ano_lectivo_id' => $aplicacao->id,
                'periodo_id' => $periodoId,
            ])
        );
    }
}
