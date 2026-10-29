<?php

namespace Modules\Usuario\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Modules\Permissao\Enums\Perfil;
use Modules\Usuario\Models\User;

/**
 * Despachado quando uma conta existente é editada e fica com um perfil (dentro da mesma transacção). Complementa
 * `UtilizadorCriado` para quem mantém registos derivados do perfil (por exemplo o docente de um Professor).
 */
class PerfilAtribuidoAUtilizador
{
    use Dispatchable;

    public function __construct(
        public readonly User $utilizador,
        public readonly Perfil $perfil,
    ) {
    }
}
