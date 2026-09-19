<?php

namespace Modules\Curso\Services;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Modules\Curso\Models\Curso;
use Modules\Estabelecimento\Models\Estabelecimento;
use Modules\Turma\Models\NivelAcademico;

class CursoConsultaService
{
    public function listar(array $filtros = [], int $porPagina = 10): LengthAwarePaginator
    {
        return Curso::where('estabelecimento_id', Estabelecimento::current()?->id)
            ->when(($filtros['estado'] ?? '') !== '', fn ($query) => $query->where('estado', $filtros['estado']))
            ->when($filtros['pesquisa'] ?? null, function ($query, $pesquisa) {
                $query->where(function ($query) use ($pesquisa) {
                    $query->where('codigo', 'like', "%{$pesquisa}%")
                        ->orWhere('nome', 'like', "%{$pesquisa}%");
                });
            })
            ->orderBy('nome')
            ->paginate($porPagina)
            ->withQueryString();
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
