<?php

namespace Modules\Plataforma\Actions;

use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\TwoFactorAuthenticationProvider;
use Laravel\Fortify\RecoveryCode;
use Modules\Core\Traits\LogService;
use Modules\Plataforma\Models\SuperAdmin;

/**
 * Conclui a activação: valida o código da aplicação contra o segredo pendente, marca-o confirmado e
 * gera os códigos de recuperação. Só aqui aparecem em claro, uma única vez.
 */
class ConfirmarDoisFactoresSuperAdminAction
{
    use LogService;

    private const TOTAL_CODIGOS = 8;

    public function __construct(private readonly TwoFactorAuthenticationProvider $provider) {}

    /**
     * @return string[] códigos de recuperação em claro
     *
     * @throws ValidationException código inválido ou activação não iniciada
     */
    public function executar(SuperAdmin $admin, string $codigo): array
    {
        return $this->comLog('Confirmar 2FA do super admin', ['super_admin_id' => $admin->id], fn () => $this->executarSemRegisto($admin, $codigo));
    }

    private function executarSemRegisto(SuperAdmin $admin, string $codigo): array
    {
        if ($admin->two_factor_secret === null || $admin->two_factor_confirmed_at !== null
            || ! $this->provider->verify(decrypt($admin->two_factor_secret), $codigo)) {
            throw ValidationException::withMessages(['code' => 'O código é inválido. Tente de novo.']);
        }

        $codigos = collect(range(1, self::TOTAL_CODIGOS))->map(fn () => RecoveryCode::generate())->all();

        $admin->forceFill([
            'two_factor_recovery_codes' => encrypt(json_encode($codigos)),
            'two_factor_confirmed_at' => now(),
        ])->save();

        return $codigos;
    }
}
