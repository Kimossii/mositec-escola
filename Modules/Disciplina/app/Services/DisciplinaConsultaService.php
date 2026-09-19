<?php

namespace Modules\Disciplina\Services;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Modules\Disciplina\Models\Disciplina;
use Modules\Estabelecimento\Models\Estabelecimento;

class DisciplinaConsultaService
{
    public function listar(array $filtros = [], int $porPagina = 10): LengthAwarePaginator
    {
        return Disciplina::where('estabelecimento_id', Estabelecimento::current()?->id)
            ->when(($filtros['estado'] ?? '') !== '', fn ($query) => $query->where('estado', $filtros['estado']))
            ->when($filtros['pesquisa'] ?? null, function ($query, $pesquisa) {
                $query->where(function ($query) use ($pesquisa) {
                    $query->whereContem('codigo', $pesquisa)
                        ->orWhereContem('nome', $pesquisa);
                });
            })
            ->orderBy('nome')
            ->paginate($porPagina)
            ->withQueryString();
    }
}
