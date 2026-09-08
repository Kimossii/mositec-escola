<?php

namespace Modules\Turma\Services;

use Illuminate\Database\Eloquent\Collection;
use Modules\Turma\Models\Turno;

class TurnoConsultaService
{
    public function listar(): Collection
    {
        return Turno::orderBy('nome')->get();
    }
}
