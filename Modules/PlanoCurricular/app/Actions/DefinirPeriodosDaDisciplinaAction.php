<?php

namespace Modules\PlanoCurricular\Actions;

use Illuminate\Support\Collection;
use Modules\PlanoCurricular\DTO\DefinirPeriodosDisciplinaDTO;
use Modules\PlanoCurricular\Models\PlanoCurricularAnoLectivo;
use Modules\PlanoCurricular\Models\PlanoCurricularDisciplina;
use Modules\Core\Traits\LogService;

class DefinirPeriodosDaDisciplinaAction
{
    use LogService;

    /** Regista a operação (conclusão ou falha) e delega em `executarSemRegisto()`. */
    public function executar(PlanoCurricularAnoLectivo $aplicacao, PlanoCurricularDisciplina $disciplina, DefinirPeriodosDisciplinaDTO $dto): Collection
    {
        return $this->comLog('Definir periodos da disciplina', $this->contextoDeArgumentos(func_get_args(), 'executar'), fn () => $this->executarSemRegisto($aplicacao, $disciplina, $dto));
    }

    private function executarSemRegisto(PlanoCurricularAnoLectivo $aplicacao, PlanoCurricularDisciplina $disciplina, DefinirPeriodosDisciplinaDTO $dto): Collection
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
