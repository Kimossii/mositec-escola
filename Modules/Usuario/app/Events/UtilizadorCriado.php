<?php

namespace Modules\Usuario\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Modules\Permissao\Enums\Perfil;
use Modules\Usuario\Models\User;

/**
 * Despachado quando uma conta nova fica criada com o seu perfil, dentro da mesma transacção: quem ouve (por exemplo o
 * módulo Docente, que mantém o registo de docente dos professores) pode falhar e desfazer a criação. O módulo Usuario
 * não conhece quem ouve.
 */
class UtilizadorCriado
{
    use Dispatchable;

    public function __construct(
        public readonly User $utilizador,
        public readonly Perfil $perfil,
    ) {
    }
}
