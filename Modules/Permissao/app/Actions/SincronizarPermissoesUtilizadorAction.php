<?php

namespace Modules\Permissao\Actions;

use Illuminate\Support\Facades\DB;
use Modules\Core\Tenancy\TenantContext;
use Modules\Permissao\Models\UserPermissao;
use Modules\Permissao\Support\PermissaoCache;
use Modules\Permissao\Exceptions\PerfilDeAlunoFixo;
use Modules\Usuario\Models\User;

class SincronizarPermissoesUtilizadorAction
{
    public function __construct(
        private readonly PermissaoCache $cache,
        private readonly GarantirAdministradorEfetivoAction $garantirAdministrador,
    ) {
    }

    public function executar(User $user, array $celulas): void
    {
        if ($user->ePerfilAluno()) {
            throw PerfilDeAlunoFixo::semPermissoesPersonalizadas();
        }

        DB::transaction(function () use ($user, $celulas) {
            UserPermissao::where('users_id', $user->id)->delete();

            $tenantId = app(TenantContext::class)->id();

            $linhas = collect($celulas)->map(fn (array $celula) => [
                'tenant_id' => $tenantId,
                'users_id' => $user->id,
                'modulo_id' => $celula['modulo_id'],
                'acao_id' => $celula['acao_id'],
                'permitido' => $celula['permitido'],
                'created_at' => now(),
                'updated_at' => now(),
            ])->all();

            if (! empty($linhas)) {
                UserPermissao::insert($linhas);
            }

            $this->cache->esquecerUtilizador($user->id);
            $this->garantirAdministrador->verificar();
        });
    }
}
