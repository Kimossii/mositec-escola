<?php

namespace Modules\Permissao\Actions;

use Modules\Core\Traits\AlternaEstado;
use Modules\Permissao\Models\Role;
use Modules\Permissao\Support\PermissaoCache;

class AlternarEstadoPerfilAction
{
    use AlternaEstado;

    public function __construct(private readonly PermissaoCache $cache)
    {
    }

    public function executar(Role $role): Role
    {
        $resultado = $this->alternarEstado($role);
        $this->cache->invalidarTudo();

        return $resultado;
    }
}
