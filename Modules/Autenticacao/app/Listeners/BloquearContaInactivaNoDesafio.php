<?php

namespace Modules\Autenticacao\Listeners;

use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Events\ValidTwoFactorAuthenticationCodeProvided;
use Modules\Core\Traits\LogService;

/** O código pode estar certo e a conta ter sido desactivada entre a senha e o código: nesse caso não se abre sessão. */
class BloquearContaInactivaNoDesafio
{
    use LogService;

    public function handle(ValidTwoFactorAuthenticationCodeProvided $evento): void
    {
        if ((int) $evento->user->estado === 1) {
            return;
        }

        $this->logWarning('Desafio 2FA recusado: conta desactivada', ['user_id' => $evento->user->getAuthIdentifier()]);

        throw ValidationException::withMessages(['code' => 'Esta conta está desactivada.']);
    }
}
