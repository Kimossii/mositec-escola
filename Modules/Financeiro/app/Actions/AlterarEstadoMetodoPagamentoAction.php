<?php

namespace Modules\Financeiro\Actions;

use Modules\Core\Enums\Estado;
use Modules\Financeiro\Models\MetodoPagamento;
use Modules\Core\Traits\LogService;

class AlterarEstadoMetodoPagamentoAction
{
    use LogService;

    /** Regista a operação (conclusão ou falha) e delega em `executarSemRegisto()`. */
    public function executar(MetodoPagamento $metodo, Estado $novoEstado): MetodoPagamento
    {
        return $this->comLog('Alterar estado método pagamento', $this->contextoDeArgumentos(func_get_args(), 'executar'), fn () => $this->executarSemRegisto($metodo, $novoEstado));
    }

    private function executarSemRegisto(MetodoPagamento $metodo, Estado $novoEstado): MetodoPagamento
    {
        $metodo->estado = $novoEstado->value;
        $metodo->save();

        return $metodo->fresh();
    }
}
