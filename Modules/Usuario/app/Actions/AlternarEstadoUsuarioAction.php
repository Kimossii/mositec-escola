<?php

namespace Modules\Usuario\Actions;

use Modules\Core\Traits\AlternaEstado;
use Modules\Usuario\Models\User;
use Modules\Core\Traits\LogService;

class AlternarEstadoUsuarioAction
{
    use LogService;

    use AlternaEstado;

    /** Regista a operação (conclusão ou falha) e delega em `executarSemRegisto()`. */
    public function executar(User $user): User
    {
        return $this->comLog('Alternar estado utilizador', $this->contextoDeArgumentos(func_get_args(), 'executar'), fn () => $this->executarSemRegisto($user));
    }

    private function executarSemRegisto(User $user): User
    {
        return $this->alternarEstado($user);
    }
}
