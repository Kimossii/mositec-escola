<?php

namespace Modules\PlanoCurricular\Actions;

use Modules\PlanoCurricular\DTO\ConfirmarAnoLectivoDTO;
use Modules\PlanoCurricular\Models\PlanoCurricular;
use Modules\PlanoCurricular\Models\PlanoCurricularAnoLectivo;

class ConfirmarPlanoParaAnoLectivoAction
{
    public function executar(PlanoCurricular $plano, ConfirmarAnoLectivoDTO $dto, int $confirmadoPorId): PlanoCurricularAnoLectivo
    {
        return PlanoCurricularAnoLectivo::create([
            'plano_curricular_id' => $plano->id,
            'ano_lectivo_id' => $dto->ano_lectivo_id,
            'confirmado_em' => now(),
            'confirmado_por' => $confirmadoPorId,
            'observacoes' => $dto->observacoes,
        ]);
    }
}
