<?php

namespace Modules\Docente\Actions;

use Modules\Core\Enums\Estado;
use Modules\Core\Traits\LogService;
use Modules\Docente\Models\Docente;

/** Activa ou desactiva o docente. Não encerra atribuições em vigor: só o tira da lista para novas atribuições. */
class AlterarEstadoDocenteAction
{
    use LogService;

    /** Regista a operação (conclusão ou falha) e delega em `executarSemRegisto()`. */
    public function executar(Docente $docente, int $estado): Docente
    {
        return $this->comLog('Alterar estado docente', $this->contextoDeArgumentos(func_get_args(), 'executar'), fn () => $this->executarSemRegisto($docente, $estado));
    }

    private function executarSemRegisto(Docente $docente, int $estado): Docente
    {
        $docente->estado = Estado::from($estado)->value;
        $docente->save();

        return $docente->fresh();
    }
}
