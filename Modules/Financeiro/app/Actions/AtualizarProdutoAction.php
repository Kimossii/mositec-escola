<?php

namespace Modules\Financeiro\Actions;

use Modules\Financeiro\DTO\ProdutoDTO;
use Modules\Financeiro\Models\Produto;
use Modules\Financeiro\Support\SincronizaDetalhesDoCatalogo;

class AtualizarProdutoAction
{
    public function __construct(private SincronizaDetalhesDoCatalogo $detalhes)
    {
    }

    public function executar(Produto $produto, ProdutoDTO $dto): Produto
    {
        $produto->update([
            'nome' => $dto->nome,
            'descricao' => $dto->descricao,
            'codigo' => $dto->codigo,
            'preco' => $dto->preco,
        ] + $dto->extras['atributos']);

        $this->detalhes->executar($produto, $dto->extras);

        return $produto->fresh();
    }
}
