<?php

namespace Modules\Financeiro\Services;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator as Paginador;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Modules\Financeiro\Models\Produto;
use Modules\Financeiro\Models\Servico;

/**
 * Listagem unificada do catálogo. Produto e Servico continuam entidades distintas: aqui só
 * se juntam, para a interface, duas consultas filtradas e paginadas em memória (um catálogo
 * escolar tem dezenas de itens, não milhares).
 */
class CatalogoConsultaService
{
    public function listar(array $filtros = [], int $porPagina = 10): LengthAwarePaginator
    {
        $tipo = $filtros['tipo'] ?? '';
        $linhas = collect();

        if ($tipo === '' || $tipo === 'produto') {
            $linhas = $linhas->concat($this->linhas(Produto::query(), 'produto', $filtros));
        }

        if ($tipo === '' || $tipo === 'servico') {
            $linhas = $linhas->concat($this->linhas(Servico::query(), 'servico', $filtros));
        }

        $ordenadas = $linhas->sortBy(fn (array $linha) => Str::lower(Str::ascii($linha['nome'])))->values();
        $pagina = Paginador::resolveCurrentPage();

        return (new Paginador(
            $ordenadas->forPage($pagina, $porPagina)->values(),
            $ordenadas->count(),
            $porPagina,
            $pagina,
            ['path' => Paginador::resolveCurrentPath()],
        ))->withQueryString();
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function linhas(Builder $consulta, string $tipo, array $filtros): Collection
    {
        return $consulta
            ->when(in_array($filtros['estado'] ?? null, ['0', '1', 0, 1], true), fn (Builder $query) => $query->where('estado', $filtros['estado']))
            ->when(is_string($filtros['pesquisa'] ?? null) && $filtros['pesquisa'] !== '', function (Builder $query) use ($filtros) {
                $pesquisa = $filtros['pesquisa'];
                $query->where(function (Builder $query) use ($pesquisa) {
                    $query->whereContem('nome', $pesquisa)->orWhereContem('codigo', $pesquisa);
                });
            })
            ->get()
            ->map(fn ($item) => [
                'id' => $item->id,
                'tipo' => $tipo,
                'codigo' => $item->codigo,
                'nome' => $item->nome,
                'descricao' => $item->descricao,
                'preco' => $item->preco->centimos(),
                'estado' => $item->estado,
                'estado_descricao' => $item->estado_descricao,
            ]);
    }
}
