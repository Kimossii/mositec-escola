<?php

namespace Modules\Autenticacao\Actions;

use Modules\Autenticacao\Models\SessaoDeUtilizador;
use Modules\Usuario\Models\User;

class TerminarOutrasSessoesAction
{
    /** Termina todas as sessões do utilizador excepto a actual e devolve quantas terminou. */
    public function executar(User $user, string $idSessaoActual): int
    {
        return SessaoDeUtilizador::where('user_id', $user->id)->where('id', '!=', $idSessaoActual)->delete();
    }
}
