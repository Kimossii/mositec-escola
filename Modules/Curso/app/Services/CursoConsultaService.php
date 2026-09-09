<?php

namespace Modules\Curso\Services;

use Illuminate\Database\Eloquent\Collection;
use Modules\Curso\Models\Curso;
use Modules\Estabelecimento\Models\Estabelecimento;

class CursoConsultaService
{
    public function listar(): Collection
    {
        return Curso::where('estabelecimento_id', Estabelecimento::current()?->id)
            ->orderBy('nome')
            ->get();
    }
}
