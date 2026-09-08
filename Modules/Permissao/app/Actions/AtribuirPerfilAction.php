<?php

namespace Modules\Permissao\Actions;

use Modules\Permissao\Support\PermissaoCache;
use Modules\Usuario\Models\User;

class AtribuirPerfilAction
{
    public function __construct(private readonly PermissaoCache $cache)
    {
    }

    public function executar(User $user, int $roleId): void
    {
        $user->roles()->syncWithoutDetaching([$roleId]);
        $this->cache->esquecerUtilizador($user->id);
    }
}
