<?php

namespace Modules\Autenticacao\Tests\Support;

use Laravel\Fortify\Contracts\TwoFactorAuthenticationProvider;
use PragmaRX\Google2FA\Google2FA;
use Modules\Usuario\Models\User;

/** Fixtures de 2FA para os testes: activa o 2FA directamente na BD de teste e gera códigos TOTP válidos. */
trait ComDoisFactores
{
    protected function comDoisFactores(User $user, array $codigosDeRecuperacao = ['recup-0001', 'recup-0002']): User
    {
        $segredo = app(TwoFactorAuthenticationProvider::class)->generateSecretKey();

        $user->forceFill([
            'two_factor_secret' => encrypt($segredo),
            'two_factor_recovery_codes' => encrypt(json_encode($codigosDeRecuperacao)),
            'two_factor_confirmed_at' => now(),
        ])->save();

        return $user->fresh();
    }

    protected function codigoTotp(User $user): string
    {
        return app(Google2FA::class)->getCurrentOtp(decrypt($user->two_factor_secret));
    }
}
