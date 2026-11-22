<?php

namespace Modules\Autenticacao\Listeners;

use Laravel\Fortify\Events\RecoveryCodeReplaced;
use Laravel\Fortify\Events\TwoFactorAuthenticationConfirmed;
use Laravel\Fortify\Events\TwoFactorAuthenticationDisabled;
use Laravel\Fortify\Events\TwoFactorAuthenticationEnabled;
use Modules\Core\Traits\LogService;

/** Regista no log o ciclo de vida do 2FA. Só `user_id` e o nome do evento: nunca segredo nem códigos de recuperação. */
class RegistarEventoDoisFactores
{
    use LogService;

    private const DESCRICOES = [
        TwoFactorAuthenticationEnabled::class => '2FA iniciado (por confirmar)',
        TwoFactorAuthenticationConfirmed::class => '2FA confirmado e activo',
        TwoFactorAuthenticationDisabled::class => '2FA desactivado',
        RecoveryCodeReplaced::class => 'Código de recuperação 2FA usado',
    ];

    public function handle(object $evento): void
    {
        $this->logInfo(self::DESCRICOES[$evento::class] ?? '2FA', ['user_id' => $evento->user->getAuthIdentifier()]);
    }
}
