<?php

namespace Modules\Turma\Services;

use Illuminate\Database\Eloquent\Collection;
use Modules\Estabelecimento\Models\Estabelecimento;
use Modules\Turma\Models\NivelAcademico;

class NivelAcademicoConsultaService
{
    public function listar(): Collection
    {
        return NivelAcademico::where('estabelecimento_id', Estabelecimento::current()?->id)
            ->orderBy('ordem')
            ->get();
    }

    public function comRelacoes(NivelAcademico $nivelAcademico): NivelAcademico
    {
        return $nivelAcademico->load(['planosCurriculares' => fn ($query) => $query->orderBy('nome')]);
    }
}
