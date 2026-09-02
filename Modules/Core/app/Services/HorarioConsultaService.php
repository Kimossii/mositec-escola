<?php

namespace Modules\Core\Services;

use Illuminate\Database\Eloquent\Collection;
use Modules\Core\Models\Horario;

class HorarioConsultaService
{
    public function listar(): Collection
    {
        return Horario::orderBy('hora_inicio')->get();
    }
}
