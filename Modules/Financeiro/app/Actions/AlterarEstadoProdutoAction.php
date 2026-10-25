<?php

namespace Modules\Financeiro\Actions;

use Modules\Core\Enums\Estado;
use Modules\Financeiro\Models\Produto;
use Modules\Core\Traits\LogService;

class AlterarEstadoProdutoAction
{
    use LogService;

    /** Regista a operação (conclusão ou falha) e delega em `executarSemRegisto()`. */
    public function executar(Produto $produto, Estado $novoEstado): Produto
    {
        return $this->comLog('Alterar estado produto', $this->contextoDeArgumentos(func_get_args(), 'executar'), fn () => $this->executarSemRegisto($produto, $novoEstado));
    }

    private function executarSemRegisto(Produto $produto, Estado $novoEstado): Produto
    {
        $produto->estado = $novoEstado->value;
        $produto->save();

        return $produto->fresh();
    }
}
