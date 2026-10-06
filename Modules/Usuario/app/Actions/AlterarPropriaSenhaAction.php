<?php

namespace Modules\Usuario\Actions;

use SensitiveParameter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\Autenticacao\Models\SessaoDeUtilizador;
use Modules\Usuario\Models\User;

class AlterarPropriaSenhaAction
{
    /**
     * Quem conhecia a senha temporária pode ter aberto outra sessão ou token: invalida
     * tudo o que pertence ao utilizador, excepto a sessão em que a troca é feita.
     */
    public function executar(User $user, #[SensitiveParameter] string $nova, string $idSessaoActual): void
    {
        DB::transaction(function () use ($user, $nova, $idSessaoActual) {
            $user->forceFill([
                'password' => Hash::make($nova),
                'deve_alterar_senha' => false,
                'remember_token' => Str::random(60),
            ])->save();

            $user->tokens()->delete();
            SessaoDeUtilizador::where('user_id', $user->id)->where('id', '!=', $idSessaoActual)->delete();
        });
    }
}
