<?php

namespace Modules\Financeiro\Actions;

use Modules\Financeiro\DTO\ProdutoDTO;
use Modules\Financeiro\Models\Produto;
use Modules\Financeiro\Support\SincronizaDetalhesDoCatalogo;

class CriarProdutoAction
{
    public function __construct(private SincronizaDetalhesDoCatalogo $detalhes)
    {
    }

    public function executar(ProdutoDTO $dto): Produto
    {
        $item = Produto::create([
            'nome' => $dto->nome,
            'descricao' => $dto->descricao,
            'codigo' => $dto->codigo,
            'preco' => $dto->preco,
        ] + $dto->extras['atributos']);

        $this->detalhes->executar($item, $dto->extras);

        return $item;
    }
}
