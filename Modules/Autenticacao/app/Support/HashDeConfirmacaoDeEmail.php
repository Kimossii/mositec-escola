<?php

namespace Modules\Autenticacao\Support;

use Modules\Usuario\Models\User;

/** Impressão do email actual do utilizador: liga a ligação de confirmação a esse endereço exacto. */
final class HashDeConfirmacaoDeEmail
{
    public static function de(User $utilizador): string
    {
        return hash('sha256', mb_strtolower((string) $utilizador->email));
    }

    public static function confere(User $utilizador, string $hash): bool
    {
        return $utilizador->email !== null && hash_equals(self::de($utilizador), $hash);
    }
}
