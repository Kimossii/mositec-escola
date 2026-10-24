<?php

namespace Modules\Permissao\Actions;

use Illuminate\Support\Facades\DB;
use Modules\Core\Tenancy\TenantContext;
use Modules\Permissao\Models\Role;
use Modules\Permissao\Models\RolePermissao;
use Modules\Permissao\Support\AcoesAplicaveis;
use Modules\Permissao\Support\PermissaoCache;

class SincronizarPermissoesPerfilAction
{
    public function __construct(
        private readonly PermissaoCache $cache,
        private readonly AcoesAplicaveis $aplicaveis,
        private readonly GarantirAdministradorEfetivoAction $garantirAdministrador,
    ) {
    }

    public function executar(Role $role, array $celulas): void
    {
        $this->aplicaveis->validarCelulas($celulas, RolePermissao::where('role_id', $role->id)->get(['modulo_id', 'acao_id'])
            ->map(fn ($p) => "{$p->modulo_id}-{$p->acao_id}")->all());

        DB::transaction(function () use ($role, $celulas) {
            RolePermissao::where('role_id', $role->id)->delete();

            $tenantId = app(TenantContext::class)->id();

            $linhas = collect($celulas)->map(fn (array $celula) => [
                'tenant_id' => $tenantId,
                'role_id' => $role->id,
                'modulo_id' => $celula['modulo_id'],
                'acao_id' => $celula['acao_id'],
                'created_at' => now(),
                'updated_at' => now(),
            ])->all();

            if (! empty($linhas)) {
                RolePermissao::insert($linhas);
            }

            $this->cache->invalidarTudo();
            $this->garantirAdministrador->verificar();
        });
    }
}
