<?php

namespace Modules\Turma\Services;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Modules\Estabelecimento\Models\Estabelecimento;
use Modules\Turma\Models\NivelAcademico;

class NivelAcademicoConsultaService
{
    public function listar(array $filtros = [], int $porPagina = 10): LengthAwarePaginator
    {
        return NivelAcademico::where('estabelecimento_id', Estabelecimento::current()?->id)
            ->when($filtros['etapa_ensino'] ?? null, fn ($query, $etapa) => $query->where('etapa_ensino', $etapa))
            ->when(($filtros['estado'] ?? '') !== '', fn ($query) => $query->where('estado', $filtros['estado']))
            ->when($filtros['pesquisa'] ?? null, function ($query, $pesquisa) {
                $query->where(function ($query) use ($pesquisa) {
                    $query->where('codigo', 'like', "%{$pesquisa}%")
                        ->orWhere('nome', 'like', "%{$pesquisa}%");
                });
            })
            ->orderBy('ordem')
            ->paginate($porPagina)
            ->withQueryString();
    }

    public function comRelacoes(NivelAcademico $nivelAcademico): NivelAcademico
    {
        return $nivelAcademico->load(['planosCurriculares' => fn ($query) => $query->with('curso:id,nome')->orderBy('nome')]);
    }
}
