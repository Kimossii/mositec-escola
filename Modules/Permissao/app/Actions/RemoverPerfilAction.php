<?php

namespace Modules\Permissao\Actions;

use Illuminate\Support\Facades\DB;
use Modules\Permissao\Models\Role;
use Modules\Permissao\Support\PermissaoCache;
use Modules\Usuario\Models\User;
use Modules\Core\Actions\RegistarAuditoriaTenantAction;
use Modules\Core\DTO\AuditoriaTenantDTO;
use Modules\Permissao\Support\InstantaneosDeAuditoria;
use Modules\Core\Traits\LogService;

class RemoverPerfilAction
{
    use LogService;

    public function __construct(
        private readonly PermissaoCache $cache,
        private readonly GarantirAdministradorEfetivoAction $garantirAdministrador,
        private readonly RegistarAuditoriaTenantAction $auditoria,
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
            $antes = InstantaneosDeAuditoria::perfisDoUtilizador($user);
            $user->roles()->detach($role->id);
            $this->cache->esquecerUtilizador($user->id);
            $this->garantirAdministrador->verificar();
            $this->auditoria->executar(new AuditoriaTenantDTO('perfil.removido', 'users', (int) $user->id, ['perfis' => $antes], ['perfis' => InstantaneosDeAuditoria::perfisDoUtilizador($user)]));
        });
    }
}
