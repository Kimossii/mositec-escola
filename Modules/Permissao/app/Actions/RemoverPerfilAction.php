<?php

namespace Modules\Permissao\Actions;

use Illuminate\Support\Facades\DB;
use Modules\Permissao\Models\Role;
use Modules\Permissao\Support\PermissaoCache;
use Modules\Usuario\Models\User;
use Modules\Core\Traits\LogService;

class RemoverPerfilAction
{
    use LogService;

    public function __construct(
        private readonly PermissaoCache $cache,
        private readonly GarantirAdministradorEfetivoAction $garantirAdministrador,
    ) {
    }

    /** Regista a operação (conclusão ou falha) e delega em `executarSemRegisto()`. */
    public function executar(User $user, Role $role): void
    {
        $this->comLog('Remover perfil', $this->contextoDeArgumentos(func_get_args(), 'executar'), fn () => $this->executarSemRegisto($user, $role));
    }

    private function executarSemRegisto(User $user, Role $role): void
    {
        DB::transaction(function () use ($user, $role) {
            $user->roles()->detach($role->id);
            $this->cache->esquecerUtilizador($user->id);
            $this->garantirAdministrador->verificar();
        });
    }
}
