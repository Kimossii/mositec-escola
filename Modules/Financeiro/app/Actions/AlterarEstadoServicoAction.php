<?php

namespace Modules\Financeiro\Actions;

use Modules\Core\Enums\Estado;
use Modules\Financeiro\Models\Servico;

class AlterarEstadoServicoAction
{
    public function executar(Servico $servico, Estado $novoEstado): Servico
    {
        $servico->estado = $novoEstado->value;
        $servico->save();

        return $servico->fresh();
    }
}
