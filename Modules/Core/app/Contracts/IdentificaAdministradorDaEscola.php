<?php

namespace Modules\Core\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Diz se um utilizador autenticado é administrador da escola. Implementado pelo módulo Permissao (dono dos perfis);
 * quem precisa de distinguir o administrador (ex.: Licenciamento) só conhece este contrato.
 */
interface IdentificaAdministradorDaEscola
{
    public function eAdministrador(Authenticatable $utilizador): bool;
}
