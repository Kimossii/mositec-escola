<?php

namespace Modules\Permissao\Actions;

use Modules\Permissao\Models\Role;
use Modules\Permissao\Support\PermissaoCache;
use Modules\Usuario\Models\User;

class RemoverPerfilAction
{
    public function __construct(private readonly PermissaoCache $cache)
    {
    }

    public function executar(User $user, Role $role): void
    {
        $user->roles()->detach($role->id);
        $this->cache->esquecerUtilizador($user->id);
    }
}
