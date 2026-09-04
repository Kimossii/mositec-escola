<?php

namespace Modules\Permissao\Actions;

use Modules\Permissao\Models\Role;
use Modules\Permissao\Support\PermissaoCache;

class AtualizarPerfilAction
{
    public function __construct(private readonly PermissaoCache $cache)
    {
    }

    public function executar(Role $role, array $dados): Role
    {
        $role->update([
            'descricao' => $dados['descricao'],
            'estado' => $dados['estado'] ?? $role->estado,
        ]);

        $this->cache->invalidarTudo();

        return $role;
    }
}
