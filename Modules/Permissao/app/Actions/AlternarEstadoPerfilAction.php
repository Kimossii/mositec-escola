<?php

namespace Modules\Permissao\Actions;

use Modules\Permissao\Models\Role;
use Modules\Usuario\Enums\EstadoUsuario;

class AlternarEstadoPerfilAction
{
    public function executar(Role $role): Role
    {
        $novoEstado = $role->estado === EstadoUsuario::ATIVO->value
            ? EstadoUsuario::INATIVO
            : EstadoUsuario::ATIVO;

        $role->update(['estado' => $novoEstado->value]);

        return $role;
    }
}
