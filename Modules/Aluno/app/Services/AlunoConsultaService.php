<?php

namespace Modules\Aluno\Services;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection as SupportCollection;
use Modules\AnoLectivo\Models\AnoLectivo;
use Modules\Aluno\Models\Aluno;
use Modules\Core\Enums\Estado;
use Modules\Curso\Models\Curso;
use Modules\Estabelecimento\Models\Estabelecimento;
use Modules\Turma\Models\NivelAcademico;
use Modules\Turma\Models\Turma;

class AlunoConsultaService
{
    public function listar(array $filtros = [], int $porPagina = 10): LengthAwarePaginator
    {
        return Aluno::with('dadosPessoa')
            ->where('estabelecimento_id', Estabelecimento::current()?->id)
            ->when($filtros['pesquisa'] ?? null, function ($query, $pesquisa) {
                $query->where(function ($query) use ($pesquisa) {
                    $query->where('numero_matricula', 'like', "%{$pesquisa}%")
                        ->orWhereHas('dadosPessoa', function ($query) use ($pesquisa) {
                            $query->where('nome_completo', 'like', "%{$pesquisa}%")
                                ->orWhere('numero_identificacao', 'like', "%{$pesquisa}%");
                        });
                });
            })
            ->when(
                ($filtros['ano_lectivo_id'] ?? null) || ($filtros['turma_id'] ?? null) || ($filtros['curso_id'] ?? null) || ($filtros['nivel_academico_id'] ?? null),
                function ($query) use ($filtros) {
                    $query->whereHas('matriculas', function ($query) use ($filtros) {
                        $query->when($filtros['ano_lectivo_id'] ?? null, fn ($query, $anoLectivoId) => $query->where('ano_lectivo_id', $anoLectivoId))
                            ->when($filtros['turma_id'] ?? null, fn ($query, $turmaId) => $query->where('turma_id', $turmaId))
                            ->when($filtros['curso_id'] ?? null, function ($query, $cursoId) {
                                $query->whereHas('turma', fn ($query) => $query->where('curso_id', $cursoId));
                            })
                            ->when($filtros['nivel_academico_id'] ?? null, function ($query, $nivelAcademicoId) {
                                $query->whereHas('turma', fn ($query) => $query->where('nivel_academico_id', $nivelAcademicoId));
                            });
                    });
                },
            )
            ->orderByDesc('numero_matricula')
            ->paginate($porPagina)
            ->withQueryString();
    }

    public function anosLectivosDisponiveis(): SupportCollection
    {
        return AnoLectivo::where('estabelecimento_id', Estabelecimento::current()?->id)
            ->orderByDesc('data_inicio')
            ->get(['id', 'nome']);
    }

    /**
     * Opções do filtro de turma. Com um ano lectivo seleccionado no filtro
     * só traz as turmas desse ano — a lista não cresce com os anos, já que
     * uma turma de outro ano nunca daria resultados ao combinar-se com ele.
     * Sem ano ("todos os anos lectivos") traz todas.
     *
     * @param  array{ano_lectivo_id?: int|string|null}  $filtros
     */
    public function turmasDisponiveis(array $filtros = []): SupportCollection
    {
        return Turma::with(['anoLectivo', 'curso', 'nivelAcademico'])
            ->when($filtros['ano_lectivo_id'] ?? null, fn ($query, $anoLectivoId) => $query->where('ano_lectivo_id', $anoLectivoId))
            ->whereHas('anoLectivo', fn ($query) => $query->where('estabelecimento_id', Estabelecimento::current()?->id))
            ->orderByDesc('ano_lectivo_id')
            ->orderBy('codigo')
            ->get();
    }

    public function cursosDisponiveis(): SupportCollection
    {
        return Curso::where('estabelecimento_id', Estabelecimento::current()?->id)
            ->where('estado', Estado::ATIVO->value)
            ->orderBy('nome')
            ->get(['id', 'nome']);
    }

    public function niveisAcademicosDisponiveis(): SupportCollection
    {
        return NivelAcademico::where('estabelecimento_id', Estabelecimento::current()?->id)
            ->where('estado', Estado::ATIVO->value)
            ->orderBy('ordem')
            ->get(['id', 'nome']);
    }
}
