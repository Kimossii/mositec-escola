<?php

namespace Modules\Financeiro\Support;

use Illuminate\Database\Eloquent\Model;
use Modules\Financeiro\Models\CatalogoAlvo;
use Modules\Financeiro\Models\Produto;

/** Grava alvos (níveis/cursos) e variantes de um item do catálogo, dentro da transacção de quem chama. */
class SincronizaDetalhesDoCatalogo
{
    /** @param array{niveis: list<int>, cursos: list<int>, variantes: list<array{nome: string, stock_quantidade: int}>} $extras */
    public function executar(Model $item, array $extras): void
    {
        $tipo = $item instanceof Produto ? 'produto' : 'servico';

        CatalogoAlvo::query()->where('item_tipo', $tipo)->where('item_id', $item->id)->delete();

        foreach ($extras['niveis'] as $nivelId) {
            CatalogoAlvo::create(['item_tipo' => $tipo, 'item_id' => $item->id, 'nivel_academico_id' => $nivelId]);
        }

        foreach ($extras['cursos'] as $cursoId) {
            CatalogoAlvo::create(['item_tipo' => $tipo, 'item_id' => $item->id, 'curso_id' => $cursoId]);
        }

        if ($item instanceof Produto) {
            $nomes = array_column($extras['variantes'], 'nome');
            $item->variantes()->whereNotIn('nome', $nomes)->delete();

            foreach ($extras['variantes'] as $variante) {
                $item->variantes()->updateOrCreate(['nome' => $variante['nome']], ['stock_quantidade' => $variante['stock_quantidade']]);
            }
        }
    }
}
