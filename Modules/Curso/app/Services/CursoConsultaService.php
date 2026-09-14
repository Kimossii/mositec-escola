<?php

namespace Modules\Curso\Services;

use Illuminate\Database\Eloquent\Collection;
use Modules\Curso\Models\Curso;
use Modules\Estabelecimento\Models\Estabelecimento;
use Modules\Turma\Models\NivelAcademico;

class CursoConsultaService
{
    public function listar(): Collection
    {
        return Curso::where('estabelecimento_id', Estabelecimento::current()?->id)
            ->orderBy('nome')
            ->get();
    }

    public function comRelacoes(Curso $curso): Curso
    {
        return $curso->load(['planosCurriculares' => fn ($query) => $query->orderBy('nome')]);
    }

    public function niveisAcademicos(): Collection
    {
        return NivelAcademico::where('estabelecimento_id', Estabelecimento::current()?->id)
            ->where('estado', 1)
            ->orderBy('ordem')
            ->get(['id', 'nome', 'ordem']);
    }
}
