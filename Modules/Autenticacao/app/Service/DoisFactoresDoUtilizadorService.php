<?php

namespace Modules\Autenticacao\Service;

use Modules\Permissao\Enums\Perfil;
use Modules\Usuario\Models\User;

/** Estado do 2FA da própria conta, para o separador Segurança das Definições. Nunca expõe segredo nem códigos. */
class DoisFactoresDoUtilizadorService
{
    /**
     * `sugerir`: só um empurrão visual (nunca obriga) para administradores da escola sem 2FA.
     *
     * @return array{activo: bool, sugerir: bool}
     */
    public function estado(User $utilizador): array
    {
        $activo = $utilizador->hasEnabledTwoFactorAuthentication();

        return [
            'activo' => $activo,
            'sugerir' => ! $activo && $utilizador->roles()->where('nome', Perfil::ADMIN_ESCOLA->value)->exists(),
        ];
    }
}
