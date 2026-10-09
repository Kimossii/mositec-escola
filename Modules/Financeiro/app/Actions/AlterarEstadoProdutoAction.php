<?php

namespace Modules\Financeiro\Actions;

use Modules\Core\Enums\Estado;
use Modules\Financeiro\Models\Produto;

class AlterarEstadoProdutoAction
{
    public function executar(Produto $produto, Estado $novoEstado): Produto
    {
        $produto->estado = $novoEstado->value;
        $produto->save();

        return $produto->fresh();
    }
}
