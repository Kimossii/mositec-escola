<?php

namespace Modules\Financeiro\Actions;

use Modules\Core\Enums\Estado;
use Modules\Financeiro\Models\Servico;
use Modules\Core\Traits\LogService;

class AlterarEstadoServicoAction
{
    use LogService;

    /** Regista a operação (conclusão ou falha) e delega em `executarSemRegisto()`. */
    public function executar(Servico $servico, Estado $novoEstado): Servico
    {
        return $this->comLog('Alterar estado serviço', $this->contextoDeArgumentos(func_get_args(), 'executar'), fn () => $this->executarSemRegisto($servico, $novoEstado));
    }

    private function executarSemRegisto(Servico $servico, Estado $novoEstado): Servico
    {
        $servico->estado = $novoEstado->value;
        $servico->save();

        return $servico->fresh();
    }
}
