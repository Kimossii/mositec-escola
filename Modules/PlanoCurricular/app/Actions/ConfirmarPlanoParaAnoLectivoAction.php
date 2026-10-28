<?php

namespace Modules\PlanoCurricular\Actions;

use Modules\PlanoCurricular\DTO\ConfirmarAnoLectivoDTO;
use Modules\PlanoCurricular\Models\PlanoCurricular;
use Modules\PlanoCurricular\Models\PlanoCurricularAnoLectivo;
use Modules\Core\Traits\LogService;

class ConfirmarPlanoParaAnoLectivoAction
{
    use LogService;

    /** Regista a operação (conclusão ou falha) e delega em `executarSemRegisto()`. */
    public function executar(PlanoCurricular $plano, ConfirmarAnoLectivoDTO $dto, int $confirmadoPorId): PlanoCurricularAnoLectivo
    {
        return $this->comLog('Confirmar plano para ano lectivo', $this->contextoDeArgumentos(func_get_args(), 'executar'), fn () => $this->executarSemRegisto($plano, $dto, $confirmadoPorId));
    }

    private function executarSemRegisto(PlanoCurricular $plano, ConfirmarAnoLectivoDTO $dto, int $confirmadoPorId): PlanoCurricularAnoLectivo
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
