<?php

namespace Modules\Usuario\Actions;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Modules\Permissao\Enums\Perfil;
use Modules\Permissao\Models\Role;
use Modules\Usuario\Models\User;

class AtualizarUsuarioAction
{
    public function atualizar(User $user, array $dados): User
    {
        return DB::transaction(function () use ($user, $dados) {
            // Utilizador aluno: nome (do registo do aluno), email e dados pessoais nunca vêm do cliente.
            $eAluno = Perfil::fromSlug($dados['perfil']) === Perfil::ALUNO
                || $user->roles->contains('nome', Perfil::ALUNO->value);

            $user->update([
                ...($eAluno ? [] : [
                    'name' => $dados['name'],
                    'email' => $dados['email'] ?? $user->email,
                ]),
                ...(!empty($dados['password']) ? ['password' => Hash::make($dados['password'])] : []),
            ]);

            $role = Role::where('nome', Perfil::fromSlug($dados['perfil'])->value)->firstOrFail();
            $user->roles()->syncWithoutDetaching([$role->id]);

            return $user->fresh();
        });
    }
}
