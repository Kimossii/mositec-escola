<?php

namespace Modules\Permissao\Actions;

use Illuminate\Support\Facades\DB;
use Modules\Core\Traits\AlternaEstado;
use Modules\Permissao\Models\Role;
use Modules\Permissao\Support\PermissaoCache;
use Modules\Core\Actions\RegistarAuditoriaTenantAction;
use Modules\Core\DTO\AuditoriaTenantDTO;
use Modules\Permissao\Support\InstantaneosDeAuditoria;
use Modules\Core\Traits\LogService;

class AlternarEstadoPerfilAction
{
    use LogService;

    use AlternaEstado;

    public function __construct(
        private readonly PermissaoCache $cache,
        private readonly GarantirAdministradorEfetivoAction $garantirAdministrador,
        private readonly RegistarAuditoriaTenantAction $auditoria,
    ) {
    }

    /** Regista a operação (conclusão ou falha) e delega em `executarSemRegisto()`. */
    public function executar(Role $role): Role
    {
        return $this->comLog('Alternar estado perfil', $this->contextoDeArgumentos(func_get_args(), 'executar'), fn () => $this->executarSemRegisto($role));
    }

    private function executarSemRegisto(Role $role): Role
    {
        return DB::transaction(function () use ($role) {
            $antes = InstantaneosDeAuditoria::perfil($role);
            $resultado = $this->alternarEstado($role);
            $this->cache->invalidarTudo();
            $this->garantirAdministrador->verificar();
            $this->auditoria->executar(new AuditoriaTenantDTO('perfil.estado_alterado', 'roles', (int) $role->id, $antes, InstantaneosDeAuditoria::perfil($role)));

            return $resultado;
        });
    }
}
