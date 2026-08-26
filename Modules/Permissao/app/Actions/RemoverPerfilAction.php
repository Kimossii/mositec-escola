<?php

namespace Modules\Permissao\Actions;

use Modules\Permissao\Models\Role;
use Modules\Usuario\Models\User;

class RemoverPerfilAction
{
    public function executar(User $user, Role $role): void
    {
        $user->roles()->detach($role->id);
    }
}
