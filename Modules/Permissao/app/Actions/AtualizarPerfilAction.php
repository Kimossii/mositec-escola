<?php

namespace Modules\Permissao\Actions;

use Illuminate\Support\Facades\DB;
use Modules\Permissao\Models\Role;
use Modules\Permissao\Support\PermissaoCache;

class AtualizarPerfilAction
{
    public function __construct(
        private readonly PermissaoCache $cache,
        private readonly GarantirAdministradorEfetivoAction $garantirAdministrador,
    ) {
    }

    public function executar(Role $role, array $dados): Role
    {
        return DB::transaction(function () use ($role, $dados) {
            $role->update([
                'descricao' => $dados['descricao'],
                'estado' => $dados['estado'] ?? $role->estado,
            ]);

            $this->cache->invalidarTudo();
            $this->garantirAdministrador->verificar();

            return $role;
        });
    }
}
