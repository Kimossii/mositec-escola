<?php

namespace Modules\Matricula\Services;

use Illuminate\Database\Eloquent\Collection;
use Modules\Aluno\Models\Aluno;
use Modules\Core\Enums\Estado;
use Modules\Estabelecimento\Models\Estabelecimento;
use Modules\Matricula\Models\Matricula;
use Modules\Turma\Models\Turma;

class MatriculaConsultaService
{
    public function listarPorAluno(Aluno $aluno): Collection
    {
        return Matricula::with(['turma.curso', 'turma.nivelAcademico', 'anoLectivo'])
            ->where('aluno_id', $aluno->id)
            ->orderByDesc('data_matricula')
            ->get();
    }

    public function turmasDisponiveis(): Collection
    {
        return Turma::with(['anoLectivo', 'curso', 'nivelAcademico'])
            ->where('estado', Estado::ATIVO->value)
            ->whereHas('anoLectivo', fn ($query) => $query->where('estabelecimento_id', Estabelecimento::current()?->id))
            ->orderByDesc('ano_lectivo_id')
            ->orderBy('codigo')
            ->get();
    }

    public function listarTodas(array $filtros = []): Collection
    {
        $estabelecimentoId = Estabelecimento::current()?->id;

        return Matricula::query()
            ->with(['aluno.dadosPessoa', 'turma.curso', 'turma.nivelAcademico', 'anoLectivo'])
            ->whereHas('aluno', fn ($query) => $query->where('estabelecimento_id', $estabelecimentoId))
            ->when($filtros['turma_id'] ?? null, fn ($query, $turmaId) => $query->where('turma_id', $turmaId))
            ->when($filtros['ano_lectivo_id'] ?? null, fn ($query, $anoLectivoId) => $query->where('ano_lectivo_id', $anoLectivoId))
            ->when($filtros['estado'] ?? null, fn ($query, $estado) => $query->where('estado', $estado))
            ->orderByDesc('data_matricula')
            ->get();
    }
}
