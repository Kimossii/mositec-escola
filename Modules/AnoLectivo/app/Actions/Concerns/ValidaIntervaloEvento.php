<?php

namespace Modules\AnoLectivo\Actions\Concerns;

use Carbon\Carbon;
use Illuminate\Validation\ValidationException;
use Modules\AnoLectivo\Models\AnoLectivo;

trait ValidaIntervaloEvento
{
    protected function garantirDentroDoIntervalo(AnoLectivo $anoLectivo, string $dataInicio, string $dataFim): void
    {
        if (Carbon::parse($dataInicio)->lt($anoLectivo->data_inicio) || Carbon::parse($dataFim)->gt($anoLectivo->data_fim)) {
            throw ValidationException::withMessages([
                'data_inicio' => 'As datas do evento têm de estar dentro do intervalo do Ano Lectivo.',
            ]);
        }
    }
}
