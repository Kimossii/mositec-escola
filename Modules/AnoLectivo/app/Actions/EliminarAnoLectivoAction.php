<?php

namespace Modules\AnoLectivo\Actions;

use Illuminate\Validation\ValidationException;
use Modules\AnoLectivo\Models\AnoLectivo;
use Modules\AnoLectivo\Support\DependenciasRegistadasDoAnoLectivo;
use Modules\Matricula\Models\Matricula;
use Modules\PlanoCurricular\Models\PlanoCurricularAnoLectivo;
use Modules\Turma\Models\Turma;
use Modules\Core\Traits\LogService;

class EliminarAnoLectivoAction
{
    use LogService;

    /** Regista a operação (conclusão ou falha) e delega em `executarSemRegisto()`. */
    public function executar(AnoLectivo $anoLectivo): void
    {
        $this->comLog('Eliminar ano lectivo', $this->contextoDeArgumentos(func_get_args(), 'executar'), fn () => $this->executarSemRegisto($anoLectivo));
    }

    private function executarSemRegisto(AnoLectivo $anoLectivo): void
    {
        if ($anoLectivo->periodos()->exists() || $anoLectivo->eventosCalendario()->exists()) {
            throw ValidationException::withMessages([
                'ano_lectivo' => 'Este Ano Lectivo tem períodos ou eventos associados e não pode ser eliminado.',
            ]);
        }

        if (Turma::where('ano_lectivo_id', $anoLectivo->id)->exists()) {
            throw ValidationException::withMessages([
                'ano_lectivo' => 'Este Ano Lectivo tem turmas associadas e não pode ser eliminado.',
            ]);
        }

        if (Matricula::where('ano_lectivo_id', $anoLectivo->id)->exists()) {
            throw ValidationException::withMessages([
                'ano_lectivo' => 'Este Ano Lectivo tem matrículas associadas e não pode ser eliminado.',
            ]);
        }

        if (PlanoCurricularAnoLectivo::where('ano_lectivo_id', $anoLectivo->id)->exists()) {
            throw ValidationException::withMessages([
                'ano_lectivo' => 'Este Ano Lectivo tem planos curriculares associados e não pode ser eliminado.',
            ]);
        }

        if (($motivo = app(DependenciasRegistadasDoAnoLectivo::class)->bloqueiaEliminacao($anoLectivo)) !== null) {
            throw ValidationException::withMessages(['ano_lectivo' => $motivo]);
        }

        $anoLectivo->delete();
    }
}
