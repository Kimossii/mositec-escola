<?php

namespace Modules\Financeiro\Actions;

use Modules\Core\Enums\Estado;
use Modules\Financeiro\Models\MetodoPagamento;

class AlterarEstadoMetodoPagamentoAction
{
    public function executar(MetodoPagamento $metodo, Estado $novoEstado): MetodoPagamento
    {
        $metodo->estado = $novoEstado->value;
        $metodo->save();

        return $metodo->fresh();
    }
}
