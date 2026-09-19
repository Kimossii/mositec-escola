<?php

namespace Modules\Turma\Services;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Modules\AnoLectivo\Models\AnoLectivo;
use Modules\Curso\Models\Curso;
use Modules\Estabelecimento\Models\Estabelecimento;
use Modules\Infraestrutura\Models\Sala;
use Modules\Turma\Models\NivelAcademico;
use Modules\Turma\Models\Turma;
use Modules\Turma\Models\Turno;

class TurmaConsultaService
{
    public function listar(array $filtros = [], int $porPagina = 10): LengthAwarePaginator
    {
        return Turma::with(['nivelAcademico', 'curso', 'turno', 'anoLectivo'])
            ->when($filtros['ano_lectivo_id'] ?? null, fn ($query, $anoLectivoId) => $query->where('ano_lectivo_id', $anoLectivoId))
            ->when($filtros['curso_id'] ?? null, fn ($query, $cursoId) => $query->where('curso_id', $cursoId))
            ->when($filtros['nivel_academico_id'] ?? null, fn ($query, $nivelId) => $query->where('nivel_academico_id', $nivelId))
            ->when($filtros['turno_id'] ?? null, fn ($query, $turnoId) => $query->where('turno_id', $turnoId))
            ->when(($filtros['estado'] ?? '') !== '', fn ($query) => $query->where('estado', $filtros['estado']))
            ->when($filtros['pesquisa'] ?? null, function ($query, $pesquisa) {
                $query->where(function ($query) use ($pesquisa) {
                    $query->where('codigo', 'like', "%{$pesquisa}%")
                        ->orWhere('nome', 'like', "%{$pesquisa}%");
                });
            })
            ->orderBy('codigo')
            ->paginate($porPagina)
            ->withQueryString();
    }

    public function opcoesFormulario(): array
    {
        $estabelecimentoId = Estabelecimento::current()?->id;

        return [
            'anoLectivos' => AnoLectivo::where('estabelecimento_id', $estabelecimentoId)->where('estado',1)->orderByDesc('nome')->get(['id', 'nome']),
            'niveisAcademicos' => NivelAcademico::where('estabelecimento_id', $estabelecimentoId)->where('estado',1)->orderBy('ordem')->get(['id', 'nome', 'etapa_ensino']),
            'cursos' => Curso::where('estabelecimento_id', $estabelecimentoId)->where('estado',1)->orderBy('nome')->get(['id', 'nome']),
            'turnos' => Turno::where('estabelecimento_id', $estabelecimentoId)->where('estado',1)->orderBy('nome')->get(['id', 'nome']),
        ];
    }

    public function salasDisponiveis(): Collection
    {
        return Sala::where('estado',0)->orderBy('codigo')->get(['id', 'codigo', 'nome']);
    }
}
