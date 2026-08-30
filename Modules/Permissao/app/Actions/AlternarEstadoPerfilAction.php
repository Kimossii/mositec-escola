<?php

namespace Modules\Permissao\Actions;

use Modules\Core\Traits\AlternaEstado;
use Modules\Permissao\Models\Role;

class AlternarEstadoPerfilAction
{
    use AlternaEstado;

    public function executar(Role $role): Role
    {
        return $this->alternarEstado($role);
    }
}
