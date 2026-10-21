<?php

namespace Modules\Financeiro\Services;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator as Paginador;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Modules\Curso\Models\Curso;
use Modules\Financeiro\Enums\MomentoObrigatorio;
use Modules\Financeiro\Models\CatalogoAlvo;
use Modules\Financeiro\Models\Produto;
use Modules\Financeiro\Models\Servico;
use Modules\Turma\Models\NivelAcademico;

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
            ->with($tipo === 'produto' ? ['variantes'] : [])
            ->get()
            ->map(fn ($item) => [
                'obrigatorio' => (bool) $item->obrigatorio,
                'momento_obrigatorio' => $item->momento_obrigatorio?->value,
                'momento_obrigatorio_descricao' => $item->momento_obrigatorio_descricao,
                'apenas_alunos_novos' => (bool) $item->apenas_alunos_novos,
                'alvos_niveis' => $this->alvos($tipo, $item->id, 'nivel_academico_id'),
                'alvos_cursos' => $this->alvos($tipo, $item->id, 'curso_id'),
                'controla_stock' => $tipo === 'produto' ? (bool) $item->controla_stock : false,
                'stock_quantidade' => $tipo === 'produto' ? (int) $item->stock_quantidade : 0,
                'tem_variantes' => $tipo === 'produto' ? (bool) $item->tem_variantes : false,
                'variantes' => $tipo === 'produto' ? $item->variantes->map(fn ($v) => ['nome' => $v->nome, 'stock_quantidade' => $v->stock_quantidade])->values()->all() : [],
                'id' => $item->id,
                'tipo' => $tipo,
                'codigo' => $item->codigo,
                'nome' => $item->nome,
                'descricao' => $item->descricao,
                'preco' => $item->preco->unidadesMenores(),
                'estado' => $item->estado,
                'estado_descricao' => $item->estado_descricao,
            ]);
    }

    /** @return array{niveis: Collection, cursos: Collection, momentos: list<array{value: int, label: string}>} opções do formulário de obrigatoriedade */
    public function opcoesDoFormulario(): array
    {
        return [
            'niveis' => NivelAcademico::query()->orderBy('ordem')->orderBy('nome')->get(['id', 'nome']),
            'cursos' => Curso::query()->orderBy('nome')->get(['id', 'nome']),
            'momentos' => MomentoObrigatorio::opcoes(),
        ];
    }

    /** @return list<int> */
    private function alvos(string $tipo, int $itemId, string $coluna): array
    {
        return CatalogoAlvo::query()->where('item_tipo', $tipo)->where('item_id', $itemId)->whereNotNull($coluna)->pluck($coluna)->map(fn ($id) => (int) $id)->all();
    }
}
