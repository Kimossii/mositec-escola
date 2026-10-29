<?php

namespace Modules\Usuario\Actions;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Modules\Permissao\Enums\Perfil;
use Modules\Permissao\Models\Role;
use Modules\Usuario\Events\PerfilAtribuidoAUtilizador;
use Modules\Usuario\Models\User;
use Modules\Core\Traits\LogService;

class AtualizarUsuarioAction
{
    use LogService;

    /** Regista a operação (conclusão ou falha) e delega em `atualizarSemRegisto()`. */
    public function atualizar(User $user, array $dados): User
    {
        return $this->comLog('Atualizar utilizador', $this->contextoDeArgumentos(func_get_args(), 'atualizar'), fn () => $this->atualizarSemRegisto($user, $dados));
    }

    private function atualizarSemRegisto(User $user, array $dados): User
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

            $perfil = Perfil::fromSlug($dados['perfil']);
            $role = Role::where('nome', $perfil->value)->firstOrFail();
            $user->roles()->syncWithoutDetaching([$role->id]);

            PerfilAtribuidoAUtilizador::dispatch($user, $perfil);

            return $user->fresh();
        });
    }
}
