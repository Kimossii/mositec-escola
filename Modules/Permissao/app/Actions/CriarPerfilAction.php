<?php

namespace Modules\Permissao\Actions;

use Modules\Permissao\Models\Role;

class CriarPerfilAction
{
    public function executar(array $dados): Role
    {
        return Role::create([
            'nome' => Role::PERFIL_PERSONALIZADO,
            'descricao' => $dados['descricao'],
            'estado' => $dados['estado'] ?? 1,
        ]);
    }
}
