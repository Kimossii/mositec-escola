<?php

namespace Modules\AnoLectivo\Services;

use Illuminate\Database\Eloquent\Collection;
use Modules\AnoLectivo\Models\AnoLectivo;

class AnoLectivoConsultaService
{
    public function listar(): Collection
    {
        return AnoLectivo::orderByDesc('data_inicio')->get();
    }

    public function comRelacoes(AnoLectivo $anoLectivo): AnoLectivo
    {
        return $anoLectivo->load(['periodos', 'eventosCalendario']);
    }
}
