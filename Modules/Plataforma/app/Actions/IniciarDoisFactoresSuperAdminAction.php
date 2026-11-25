<?php

namespace Modules\Plataforma\Actions;

use DomainException;
use Laravel\Fortify\Contracts\TwoFactorAuthenticationProvider;
use Modules\Core\Traits\LogService;
use Modules\Plataforma\Models\SuperAdmin;

/**
 * Começa a activação do 2FA: gera e guarda (cifrado) um segredo novo, ainda por confirmar.
 * Enquanto não for confirmado o login não pede desafio. Recomeçar substitui o segredo anterior.
 */
class IniciarDoisFactoresSuperAdminAction
{
    use LogService;

    public function __construct(private readonly TwoFactorAuthenticationProvider $provider) {}

    /**
     * @return array{qr: string, chave: string}
     *
     * @throws DomainException se o 2FA já está activo
     */
    public function executar(SuperAdmin $admin): array
    {
        return $this->comLog('Iniciar 2FA do super admin', ['super_admin_id' => $admin->id], fn () => $this->executarSemRegisto($admin));
    }

    private function executarSemRegisto(SuperAdmin $admin): array
    {
        if ($admin->hasEnabledTwoFactorAuthentication()) {
            throw new DomainException('A autenticação em dois factores já está activa.');
        }

        $chave = $this->provider->generateSecretKey();

        $admin->forceFill([
            'two_factor_secret' => encrypt($chave),
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ])->save();

        return ['qr' => $admin->twoFactorQrCodeSvg(), 'chave' => $chave];
    }
}
