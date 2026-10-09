<?php

namespace Modules\Financeiro\Actions;

use Modules\Financeiro\DTO\ProdutoDTO;
use Modules\Financeiro\Models\Produto;

class AtualizarProdutoAction
{
    public function executar(Produto $produto, ProdutoDTO $dto): Produto
    {
        $produto->update([
            'nome' => $dto->nome,
            'descricao' => $dto->descricao,
            'codigo' => $dto->codigo,
            'preco' => $dto->preco,
        ]);

        return $produto->fresh();
    }
}
