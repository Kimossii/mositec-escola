<?php

namespace Modules\Permissao\Actions;

use Illuminate\Support\Facades\DB;
use Modules\Permissao\Models\Role;
use Modules\Permissao\Support\PermissaoCache;
use Modules\Usuario\Models\User;

class RemoverPerfilAction
{
    public function __construct(
        private readonly PermissaoCache $cache,
        private readonly GarantirAdministradorEfetivoAction $garantirAdministrador,
    ) {
    }

    public function executar(User $user, Role $role): void
    {
        DB::transaction(function () use ($user, $role) {
            $user->roles()->detach($role->id);
            $this->cache->esquecerUtilizador($user->id);
            $this->garantirAdministrador->verificar();
        });
    }
}
