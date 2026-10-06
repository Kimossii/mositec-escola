<?php

namespace Modules\Permissao\Actions;

use Modules\Permissao\Enums\Perfil;
use Modules\Permissao\Exceptions\PerfilDeAlunoFixo;
use Modules\Permissao\Models\Role;
use Modules\Permissao\Support\PermissaoCache;
use Modules\Usuario\Models\User;

class AtribuirPerfilAction
{
    public function __construct(private readonly PermissaoCache $cache)
    {
    }

    public function executar(User $user, int $roleId): void
    {
        $perfilAluno = Role::where('nome', Perfil::ALUNO->value)->value('id');

        if ($user->ePerfilAluno() || ($perfilAluno !== null && (int) $roleId === (int) $perfilAluno)) {
            throw PerfilDeAlunoFixo::semPerfisExtra();
        }

        $user->roles()->syncWithoutDetaching([$roleId]);
        $this->cache->esquecerUtilizador($user->id);
    }
}
