<?php

namespace Modules\Core\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;

/** Implementação nula de IdentificaAdministradorDaEscola: sem o módulo Permissao ninguém é administrador (e nada falha por falta de binding). */
class NinguemEAdministrador implements IdentificaAdministradorDaEscola
{
    public function eAdministrador(Authenticatable $utilizador): bool
    {
        return false;
    }
}
