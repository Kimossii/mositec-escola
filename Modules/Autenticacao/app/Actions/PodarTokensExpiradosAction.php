<?php

namespace Modules\Autenticacao\Actions;

use Modules\Autenticacao\Models\TokenDeAcesso;

/**
 * Apaga os tokens do tenant corrente expirados há mais de `$horas` horas, tal como
 * `sanctum:prune-expired`: pela data `expires_at` e, se configurada, pela expiração global.
 * O model tem scope, por isso só toca no tenant do contexto.
 */
class PodarTokensExpiradosAction
{
    public function executar(int $horas = 24): int
    {
        $apagados = TokenDeAcesso::where('expires_at', '<', now()->subHours($horas))->delete();

        if ($expiracao = config('sanctum.expiration')) {
            $apagados += TokenDeAcesso::where('created_at', '<', now()->subMinutes($expiracao + ($horas * 60)))->delete();
        }

        return $apagados;
    }
}
