<?php

namespace Modules\Usuario\Actions;

use Modules\Usuario\Enums\EstadoUsuario;
use Modules\Usuario\Models\User;

class AlternarEstadoUsuarioAction
{
    public function executar(User $user): User
    {
        $novoEstado = $user->estado === EstadoUsuario::ATIVO->value
            ? EstadoUsuario::INATIVO
            : EstadoUsuario::ATIVO;

        $user->update(['estado' => $novoEstado->value]);

        return $user;
    }
}
