<?php

namespace Modules\Usuario\Actions;

use Modules\Core\Traits\AlternaEstado;
use Modules\Usuario\Models\User;

class AlternarEstadoUsuarioAction
{
    use AlternaEstado;

    public function executar(User $user): User
    {
        return $this->alternarEstado($user);
    }
}
