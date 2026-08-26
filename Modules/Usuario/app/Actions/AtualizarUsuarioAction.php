<?php

namespace Modules\Usuario\Actions;

use Illuminate\Support\Facades\DB;
use Modules\Permissao\Actions\SincronizarPermissoesUtilizadorAction;
use Modules\Permissao\Enums\Perfil;
use Modules\Permissao\Models\Role;
use Modules\Usuario\Models\User;

class AtualizarUsuarioAction
{
    public function __construct(
        private SincronizarPermissoesUtilizadorAction $sincronizarPermissoes,
    ) {}

    public function atualizar(User $user, array $dados): User
    {
        return DB::transaction(function () use ($user, $dados) {
            $user->update([
                'name' => $dados['name'],
                'email' => $dados['email'] ?? $user->email,
            ]);

            $role = Role::where('nome', Perfil::fromSlug($dados['perfil'])->value)->firstOrFail();
            $user->roles()->syncWithoutDetaching([$role->id]);

            $this->sincronizarPermissoes->executar($user, $dados['celulas'] ?? []);

            return $user->fresh();
        });
    }
}
