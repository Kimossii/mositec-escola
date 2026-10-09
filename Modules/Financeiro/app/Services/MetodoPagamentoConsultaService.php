<?php

namespace Modules\Financeiro\Services;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Modules\Financeiro\Models\MetodoPagamento;

class MetodoPagamentoConsultaService
{
    public function listar(array $filtros = [], int $porPagina = 10): LengthAwarePaginator
    {
        return MetodoPagamento::query()
            ->when(in_array($filtros['estado'] ?? null, ['0', '1', 0, 1], true), fn ($query) => $query->where('estado', $filtros['estado']))
            ->when(is_string($filtros['pesquisa'] ?? null) && $filtros['pesquisa'] !== '', fn ($query) => $query->whereContem('nome', $filtros['pesquisa']))
            ->orderBy('nome')
            ->paginate($porPagina)
            ->withQueryString();
    }
}
