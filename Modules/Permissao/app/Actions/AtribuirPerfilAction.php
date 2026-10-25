<?php

namespace Modules\Permissao\Actions;

use Modules\Permissao\Enums\Perfil;
use Modules\Permissao\Exceptions\PerfilDeAlunoFixo;
use Modules\Permissao\Models\Role;
use Modules\Permissao\Support\PermissaoCache;
use Modules\Usuario\Models\User;
use Modules\Core\Traits\LogService;

class AtribuirPerfilAction
{
    use LogService;

    public function __construct(private readonly PermissaoCache $cache)
    {
    }

    /** Regista a operação (conclusão ou falha) e delega em `executarSemRegisto()`. */
    public function executar(User $user, int $roleId): void
    {
        $this->comLog('Atribuir perfil', $this->contextoDeArgumentos(func_get_args(), 'executar'), fn () => $this->executarSemRegisto($user, $roleId));
    }

    private function executarSemRegisto(User $user, int $roleId): void
    {
        $perfilAluno = Role::where('nome', Perfil::ALUNO->value)->value('id');

        if ($user->ePerfilAluno() || ($perfilAluno !== null && (int) $roleId === (int) $perfilAluno)) {
            throw PerfilDeAlunoFixo::semPerfisExtra();
        }

        $user->roles()->syncWithoutDetaching([$roleId]);
        $this->cache->esquecerUtilizador($user->id);
    }
}
