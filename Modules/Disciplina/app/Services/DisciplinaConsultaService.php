<?php

namespace Modules\Disciplina\Services;

use Illuminate\Database\Eloquent\Collection;
use Modules\Disciplina\Models\Disciplina;
use Modules\Estabelecimento\Models\Estabelecimento;

class DisciplinaConsultaService
{
    public function listar(): Collection
    {
        return Disciplina::where('estabelecimento_id', Estabelecimento::current()?->id)
            ->orderBy('nome')
            ->get();
    }
}
