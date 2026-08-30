<?php

namespace Modules\Permissao\Actions;

use Modules\Permissao\Models\Role;

class AtualizarPerfilAction
{
    public function executar(Role $role, array $dados): Role
    {
        $role->update([
            'descricao' => $dados['descricao'],
            'estado' => $dados['estado'] ?? $role->estado,
        ]);

        return $role;
    }
}
