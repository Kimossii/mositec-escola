<?php

namespace Modules\Plataforma\Actions;

use Laravel\Fortify\Contracts\TwoFactorAuthenticationProvider;
use Modules\Core\Traits\LogService;
use Modules\Plataforma\Models\SuperAdmin;

/**
 * Valida o segundo passo do login: código TOTP (o provider recusa repetições do mesmo código) ou um
 * código de recuperação, que é consumido (substituído por outro) ao acertar.
 */
class VerificarDesafioDoisFactoresAction
{
    use LogService;

    public function __construct(private readonly TwoFactorAuthenticationProvider $provider) {}

    /** Regista a operação com o id da conta apenas: nunca os códigos recebidos. */
    public function executar(SuperAdmin $admin, ?string $codigo, ?string $codigoDeRecuperacao): bool
    {
        return $this->comLog('Verificar desafio 2FA do super admin', ['super_admin_id' => $admin->id], fn () => $this->executarSemRegisto($admin, $codigo, $codigoDeRecuperacao));
    }

    private function executarSemRegisto(SuperAdmin $admin, ?string $codigo, ?string $codigoDeRecuperacao): bool
    {
        if (! $admin->hasEnabledTwoFactorAuthentication()) {
            return false;
        }

        if ($codigoDeRecuperacao !== null && $codigoDeRecuperacao !== '') {
            foreach ($admin->recoveryCodes() as $guardado) {
                if (hash_equals($guardado, $codigoDeRecuperacao)) {
                    $admin->replaceRecoveryCode($guardado);

                    return true;
                }
            }

            return false;
        }

        return $codigo !== null && $codigo !== ''
            && $this->provider->verify(decrypt($admin->two_factor_secret), $codigo);
    }
}
