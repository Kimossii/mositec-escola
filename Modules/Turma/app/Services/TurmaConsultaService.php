<?php

namespace Modules\Turma\Services;

use Illuminate\Database\Eloquent\Collection;
use Modules\Turma\Models\Turma;

class TurmaConsultaService
{
    public function listar(): Collection
    {
        return Turma::orderBy('codigo')->get();
    }
}
