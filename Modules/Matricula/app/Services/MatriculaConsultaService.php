<?php

namespace Modules\Matricula\Services;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as SupportCollection;
use Modules\Aluno\Models\Aluno;
use Modules\Core\Enums\Estado;
use Modules\Estabelecimento\Models\Estabelecimento;
use Modules\Matricula\Models\InscricaoDisciplina;
use Modules\Matricula\Models\Matricula;
use Modules\Turma\Models\Turma;

class MatriculaConsultaService
{
    public function listarPorAluno(Aluno $aluno, array $filtros = [], int $porPagina = 10): LengthAwarePaginator
    {
        return Matricula::with(['turma.curso', 'turma.nivelAcademico', 'anoLectivo'])
            ->where('aluno_id', $aluno->id)
            ->when($filtros['ano_lectivo_id'] ?? null, fn ($query, $anoLectivoId) => $query->where('ano_lectivo_id', $anoLectivoId))
            ->when($filtros['pesquisa'] ?? null, function ($query, $pesquisa) {
                $query->where(function ($query) use ($pesquisa) {
                    $query->where('numero_registo_matricula', 'like', "%{$pesquisa}%")
                        ->orWhereHas('turma', function ($query) use ($pesquisa) {
                            $query->where('codigo', 'like', "%{$pesquisa}%")
                                ->orWhere('nome', 'like', "%{$pesquisa}%");
                        });
                });
            })
            ->orderByDesc('data_matricula')
            ->orderByDesc('id')
            ->paginate($porPagina)
            ->appends($filtros);
    }

    /**
     * Anos lectivos distintos em que o aluno já teve matrícula — usado para
     * as opções do filtro por ano lectivo na página do aluno (não pode vir
     * da página paginada, que só tem uma fatia dos registos).
     */
    public function anosLectivosComMatricula(Aluno $aluno): SupportCollection
    {
        return Matricula::where('aluno_id', $aluno->id)
            ->select('ano_lectivo_id')
            ->distinct()
            ->with('anoLectivo:id,nome,data_inicio')
            ->get()
            ->pluck('anoLectivo')
            ->filter()
            ->sortByDesc('data_inicio')
            ->values();
    }

    public function historicoDaMatricula(Matricula $matricula): Collection
    {
        return $matricula->historico()
            ->with('utilizador')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get();
    }

    public function listarDisciplinasDaMatricula(Matricula $matricula): Collection
    {
        return InscricaoDisciplina::with('planoCurricularDisciplina.disciplina')
            ->where('matricula_id', $matricula->id)
            ->orderBy('data_inscricao')
            ->get();
    }

    public function turmasDisponiveis(): Collection
    {
        return Turma::with(['anoLectivo', 'curso', 'nivelAcademico', 'turno'])
            ->where('estado', Estado::ATIVO->value)
            ->whereHas('anoLectivo', fn ($query) => $query->where('estabelecimento_id', Estabelecimento::current()?->id))
            ->orderByDesc('ano_lectivo_id')
            ->orderBy('codigo')
            ->get();
    }

    public function listarTodas(array $filtros = [], int $porPagina = 10): LengthAwarePaginator
    {
        $estabelecimentoId = Estabelecimento::current()?->id;

        return Matricula::query()
            ->with(['aluno.dadosPessoa', 'turma.curso', 'turma.nivelAcademico', 'anoLectivo'])
            ->whereHas('aluno', fn ($query) => $query->where('estabelecimento_id', $estabelecimentoId))
            ->when($filtros['turma_id'] ?? null, fn ($query, $turmaId) => $query->where('turma_id', $turmaId))
            ->when($filtros['ano_lectivo_id'] ?? null, fn ($query, $anoLectivoId) => $query->where('ano_lectivo_id', $anoLectivoId))
            ->when($filtros['estado'] ?? null, fn ($query, $estado) => $query->where('estado', $estado))
            ->when($filtros['pesquisa'] ?? null, function ($query, $pesquisa) {
                $query->whereHas('aluno', function ($query) use ($pesquisa) {
                    $query->where('numero_matricula', 'like', "%{$pesquisa}%")
                        ->orWhereHas('dadosPessoa', function ($query) use ($pesquisa) {
                            $query->where('nome_completo', 'like', "%{$pesquisa}%")
                                ->orWhere('numero_identificacao', 'like', "%{$pesquisa}%");
                        });
                });
            })
            ->orderByDesc('data_matricula')
            ->orderByDesc('id')
            ->paginate($porPagina)
            ->withQueryString();
    }
}
