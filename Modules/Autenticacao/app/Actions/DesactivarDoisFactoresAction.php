<?php

namespace Modules\Autenticacao\Actions;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Autenticacao\Models\SessaoDeUtilizador;
use Modules\Core\Traits\LogService;
use Modules\Usuario\Models\User;

/**
 * Repõe (desactiva) o 2FA de uma conta quando o utilizador perdeu a aplicação e os códigos de recuperação.
 * Termina também tokens e sessões do alvo: quem repõe não sabe se a conta está comprometida.
 *
 * Sem `$autor` (recuperação por linha de comandos) `editado_por` fica nulo, como em RedefinirSenhaUsuarioAction.
 */
class DesactivarDoisFactoresAction
{
    use LogService;

    /** Regista a operação (conclusão ou falha) e delega em `executarSemRegisto()`. */
    public function executar(User $alvo, ?User $autor = null): void
    {
        $this->comLog('Repor 2FA do utilizador', $this->contextoDeArgumentos(func_get_args(), 'executar'), fn () => $this->executarSemRegisto($alvo, $autor));
    }

    private function executarSemRegisto(User $alvo, ?User $autor): void
    {
        DB::transaction(function () use ($alvo, $autor) {
            $alvo->forceFill([
                'two_factor_secret' => null,
                'two_factor_recovery_codes' => null,
                'two_factor_confirmed_at' => null,
                // Invalida o cookie «lembrar-me»: senão o alvo (ou um intruso) volta a entrar sem sessão nem token.
                'remember_token' => Str::random(60),
                'editado_por' => $autor?->id,
            ])->save();

            $alvo->tokens()->delete();
            SessaoDeUtilizador::where('user_id', $alvo->id)->delete();
        });
    }
}
