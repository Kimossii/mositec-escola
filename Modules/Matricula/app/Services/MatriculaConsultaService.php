<?php

namespace Modules\Matricula\Services;

use Illuminate\Database\Eloquent\Collection;
use Modules\Aluno\Models\Aluno;
use Modules\Matricula\Models\Matricula;

class MatriculaConsultaService
{
    public function listarPorAluno(Aluno $aluno): Collection
    {
        return Matricula::with(['turma.curso', 'anoLectivo'])
            ->where('aluno_id', $aluno->id)
            ->orderByDesc('data_matricula')
            ->get();
    }
}
