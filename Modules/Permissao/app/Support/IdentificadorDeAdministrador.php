<?php

namespace Modules\Permissao\Support;

use Illuminate\Contracts\Auth\Authenticatable;
use Modules\Core\Contracts\IdentificaAdministradorDaEscola;
use Modules\Permissao\Enums\Perfil;

/** Administrador = quem tem o perfil ADMIN_ESCOLA (o mesmo critério da recuperação de administrador). */
class IdentificadorDeAdministrador implements IdentificaAdministradorDaEscola
{
    public function eAdministrador(Authenticatable $utilizador): bool
    {
        if (! method_exists($utilizador, 'roles')) {
            return false;
        }

        return $utilizador->roles()->where('nome', Perfil::ADMIN_ESCOLA->value)->exists();
    }
}
