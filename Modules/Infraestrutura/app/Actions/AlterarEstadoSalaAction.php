<?php

namespace Modules\Infraestrutura\Actions;

use Modules\Infraestrutura\Enums\EstadoSala;
use Modules\Infraestrutura\Models\Sala;
use Modules\Core\Traits\LogService;

class AlterarEstadoSalaAction
{
    use LogService;

    /** Regista a operação (conclusão ou falha) e delega em `alterarSemRegisto()`. */
    public function alterar(Sala $sala, EstadoSala $novoEstado): Sala
    {
        return $this->comLog('Alterar estado sala', $this->contextoDeArgumentos(func_get_args(), 'alterar'), fn () => $this->alterarSemRegisto($sala, $novoEstado));
    }

    private function alterarSemRegisto(Sala $sala, EstadoSala $novoEstado): Sala
    {
        $sala->update(['estado' => $novoEstado->value]);

        return $sala->fresh();
    }
}
