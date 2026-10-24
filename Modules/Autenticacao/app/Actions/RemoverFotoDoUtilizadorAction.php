<?php

namespace Modules\Autenticacao\Actions;

use Modules\Usuario\Models\User;

class RemoverFotoDoUtilizadorAction
{
    public function executar(User $user): void
    {
        $anterior = $user->foto_path;

        $user->forceFill(['foto_path' => null])->save();

        AtualizarFotoDoUtilizadorAction::apagar($anterior);
    }
}
