<?php

namespace Modules\Infraestrutura\Services;

use Illuminate\Database\Eloquent\Collection;
use Modules\Infraestrutura\Models\Sala;

class SalaConsultaService
{
    public function listar(): Collection
    {
        return Sala::orderBy('codigo')->get();
    }
}
