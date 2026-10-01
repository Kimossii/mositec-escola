<?php

namespace Modules\Usuario\Actions;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\Autenticacao\Models\SessaoDeUtilizador;
use Modules\Usuario\Models\User;

class RedefinirSenhaUsuarioAction
{
    /**
     * Devolve a senha temporária em claro, e só isso: nunca é registada em logs,
     * eventos nem guardada em claro. O chamador mostra-a uma única vez.
     *
     * Sem $autor (recuperação pela linha de comandos, sem utilizador autenticado)
     * `senha_redefinida_por` e `editado_por` ficam nulos. $senha permite ao chamador
     * fornecer a origem da senha (ex.: GeradorSenhaTemporaria).
     */
    public function executar(User $alvo, ?User $autor, ?string $senha = null): string
    {
        $senha ??= Str::password(14);

        DB::transaction(function () use ($alvo, $autor, $senha) {
            $alvo->forceFill([
                'password' => Hash::make($senha),
                'deve_alterar_senha' => true,
                'senha_redefinida_por' => $autor?->id,
                'senha_redefinida_em' => now(),
                'remember_token' => Str::random(60),
                'editado_por' => $autor?->id,
            ])->save();

            $alvo->tokens()->delete();
            SessaoDeUtilizador::where('user_id', $alvo->id)->delete();
        });

        return $senha;
    }
}
