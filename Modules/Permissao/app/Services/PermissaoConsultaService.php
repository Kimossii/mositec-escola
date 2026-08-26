<?php

namespace Modules\Permissao\Services;

use Modules\Permissao\Models\RolePermissao;
use Modules\Usuario\Models\User;

class PermissaoConsultaService
{
    public function permissoesHerdadas(User $user): array
    {
        $roleIds = $user->roles()->pluck('roles.id');

        return RolePermissao::whereIn('role_id', $roleIds)
            ->get(['modulo_id', 'acao_id'])
            ->unique(fn ($permissao) => "{$permissao->modulo_id}-{$permissao->acao_id}")
            ->values()
            ->map(fn ($permissao) => [
                'modulo_id' => $permissao->modulo_id,
                'acao_id' => $permissao->acao_id,
            ])
            ->all();
    }
}
