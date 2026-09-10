<?php

namespace Modules\Aluno\Services;

use Illuminate\Database\Eloquent\Collection;
use Modules\Aluno\Models\Aluno;
use Modules\Estabelecimento\Models\Estabelecimento;

class AlunoConsultaService
{
    public function listar(): Collection
    {
        return Aluno::with('dadosPessoa')
            ->where('estabelecimento_id', Estabelecimento::current()?->id)
            ->orderBy('numero_matricula')
            ->get();
    }
}
