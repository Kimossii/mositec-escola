<?php

namespace Modules\Autenticacao\Support;

use Modules\Usuario\Models\User;

/**
 * Impressão que liga a ligação de recuperação ao ESTADO ACTUAL da conta (senha e email). Basta a senha ou o email mudarem
 * para a ligação deixar de valer: é isso que a torna de uso único, sem guardar nada na base de dados. É um HMAC com a chave
 * da aplicação, para nunca expor nada derivado do hash da senha.
 */
final class HashDeRecuperacaoDeSenha
{
    public static function de(User $utilizador): string
    {
        return hash_hmac('sha256', $utilizador->password.'|'.mb_strtolower((string) $utilizador->email), (string) config('app.key'));
    }

    public static function confere(User $utilizador, string $hash): bool
    {
        return hash_equals(self::de($utilizador), $hash);
    }
}
