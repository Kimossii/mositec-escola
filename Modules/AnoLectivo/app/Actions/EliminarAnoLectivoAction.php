<?php

namespace Modules\AnoLectivo\Actions;

use Illuminate\Validation\ValidationException;
use Modules\AnoLectivo\Models\AnoLectivo;

class EliminarAnoLectivoAction
{
    public function executar(AnoLectivo $anoLectivo): void
    {
        if ($anoLectivo->periodos()->exists() || $anoLectivo->eventosCalendario()->exists()) {
            throw ValidationException::withMessages([
                'ano_lectivo' => 'Este Ano Lectivo tem períodos ou eventos associados e não pode ser eliminado.',
            ]);
        }

        $anoLectivo->delete();
    }
}
