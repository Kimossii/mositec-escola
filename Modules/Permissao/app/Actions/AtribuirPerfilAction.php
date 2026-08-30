<?php

namespace Modules\Permissao\Actions;

use Modules\Usuario\Models\User;

class AtribuirPerfilAction
{
    public function executar(User $user, int $roleId): void
    {
        $user->roles()->syncWithoutDetaching([$roleId]);
    }
}
