<?php

namespace Modules\Permissao\Actions;

use Illuminate\Support\Facades\DB;
use Modules\Permissao\Models\UserPermissao;
use Modules\Permissao\Support\PermissaoCache;
use Modules\Usuario\Models\User;

class SincronizarPermissoesUtilizadorAction
{
    public function __construct(private readonly PermissaoCache $cache)
    {
    }

    public function executar(User $user, array $celulas): void
    {
        DB::transaction(function () use ($user, $celulas) {
            UserPermissao::where('users_id', $user->id)->delete();

            $linhas = collect($celulas)->map(fn (array $celula) => [
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
        });

        $this->cache->esquecerUtilizador($user->id);
    }
}
