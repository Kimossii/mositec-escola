<?php

namespace Modules\Permissao\Actions;

use Illuminate\Validation\ValidationException;
use Modules\Permissao\Models\Role;

class EliminarPerfilAction
{
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
    }
}
