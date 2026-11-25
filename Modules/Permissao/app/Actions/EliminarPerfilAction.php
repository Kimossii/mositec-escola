<?php

namespace Modules\Permissao\Actions;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Permissao\Models\Role;
use Modules\Permissao\Support\PermissaoCache;
use Modules\Core\Actions\RegistarAuditoriaTenantAction;
use Modules\Core\DTO\AuditoriaTenantDTO;
use Modules\Permissao\Support\InstantaneosDeAuditoria;
use Modules\Core\Traits\LogService;

class EliminarPerfilAction
{
    use LogService;

    public function __construct(
        private readonly PermissaoCache $cache,
        private readonly GarantirAdministradorEfetivoAction $garantirAdministrador,
        private readonly RegistarAuditoriaTenantAction $auditoria,
    ) {
    }

    /** Regista a operação (conclusão ou falha) e delega em `executarSemRegisto()`. */
    public function executar(Role $role): void
    {
        $this->comLog('Eliminar perfil', $this->contextoDeArgumentos(func_get_args(), 'executar'), fn () => $this->executarSemRegisto($role));
    }

    private function executarSemRegisto(Role $role): void
    {
        if ($role->eSistema()) {
            throw ValidationException::withMessages([
                'perfil' => 'Perfis de sistema não podem ser eliminados.',
            ]);
        }

        if ($role->users()->exists()) {
            throw ValidationException::withMessages([
                'perfil' => 'Este perfil tem utilizadores atribuídos e não pode ser eliminado.',
            ]);
        }

        DB::transaction(function () use ($role) {
            $antes = InstantaneosDeAuditoria::perfil($role);
            $role->delete();
            $this->cache->invalidarTudo();
            $this->garantirAdministrador->verificar();
            $this->auditoria->executar(new AuditoriaTenantDTO('perfil.eliminado', 'roles', (int) $role->id, $antes, array_fill_keys(array_keys($antes), null)));
        });
    }
}
