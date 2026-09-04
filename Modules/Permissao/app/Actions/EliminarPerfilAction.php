<?php

namespace Modules\Permissao\Actions;

use Illuminate\Validation\ValidationException;
use Modules\Permissao\Models\Role;
use Modules\Permissao\Support\PermissaoCache;

class EliminarPerfilAction
{
    public function __construct(private readonly PermissaoCache $cache)
    {
    }

    public function executar(Role $role): void
    {
        if ($role->eSistema()) {
            throw ValidationException::withMessages([
                'perfil' => 'Perfis de sistema não podem ser eliminados.',
            ]);
        }

        if ($role->users()->exists()) {
            throw ValidationException::withMessages([
                'perfil' => 'Este perfil tem utilizadores atribuídos e não pode ser eliminado.',
            ]);
        }

        $role->delete();
        $this->cache->invalidarTudo();
    }
}
