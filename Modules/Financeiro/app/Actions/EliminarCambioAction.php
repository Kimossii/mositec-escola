<?php

namespace Modules\Financeiro\Actions;

use Modules\Financeiro\Models\Cambio;
use Modules\Financeiro\Support\ViolacaoDeChave;
use Modules\Core\Traits\LogService;

class EliminarCambioAction
{
    use LogService;

    /** Regista a operação (conclusão ou falha) e delega em `executarSemRegisto()`. */
    public function executar(Cambio $cambio): void
    {
        $this->comLog('Eliminar câmbio', $this->contextoDeArgumentos(func_get_args(), 'executar'), fn () => $this->executarSemRegisto($cambio));
    }

    private function executarSemRegisto(Cambio $cambio): void
    {
        ViolacaoDeChave::comoValidacao(fn () => $cambio->delete());
    }
}
