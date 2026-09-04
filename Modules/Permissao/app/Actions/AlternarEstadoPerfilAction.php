<?php

namespace Modules\Permissao\Actions;

use Illuminate\Support\Facades\DB;
use Modules\Core\Traits\AlternaEstado;
use Modules\Permissao\Models\Role;
use Modules\Permissao\Support\PermissaoCache;

class AlternarEstadoPerfilAction
{
    use AlternaEstado;

    public function __construct(
        private readonly PermissaoCache $cache,
        private readonly GarantirAdministradorEfetivoAction $garantirAdministrador,
    ) {
    }

    public function executar(Role $role): Role
    {
        return DB::transaction(function () use ($role) {
            $resultado = $this->alternarEstado($role);
            $this->cache->invalidarTudo();
            $this->garantirAdministrador->verificar();

            return $resultado;
        });
    }
}
