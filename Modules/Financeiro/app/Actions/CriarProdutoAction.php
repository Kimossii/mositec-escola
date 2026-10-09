<?php

namespace Modules\Financeiro\Actions;

use Modules\Financeiro\DTO\ProdutoDTO;
use Modules\Financeiro\Models\Produto;

class CriarProdutoAction
{
    public function executar(ProdutoDTO $dto): Produto
    {
        return Produto::create([
            'nome' => $dto->nome,
            'descricao' => $dto->descricao,
            'codigo' => $dto->codigo,
            'preco' => $dto->preco,
        ]);
    }
}
