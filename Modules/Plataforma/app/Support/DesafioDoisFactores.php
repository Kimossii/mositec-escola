<?php

namespace Modules\Plataforma\Support;

use Illuminate\Contracts\Session\Session;
use Modules\Plataforma\Models\SuperAdmin;

/**
 * Desafio de 2FA pendente na sessão: o super admin já acertou a senha mas ainda não o código.
 * Só guarda o id e a validade; a conta é relida a cada uso, por isso uma conta desactivada ou que
 * entretanto perdeu o 2FA deixa de ter desafio.
 */
final class DesafioDoisFactores
{
    public const CHAVE = 'plataforma.desafio';

    public const MINUTOS = 5;

    public static function abrir(Session $sessao, SuperAdmin $admin): void
    {
        $sessao->put(self::CHAVE, ['id' => $admin->id, 'ate' => now()->addMinutes(self::MINUTOS)->getTimestamp()]);
    }

    public static function adminPendente(Session $sessao): ?SuperAdmin
    {
        $desafio = $sessao->get(self::CHAVE);

        if (! is_array($desafio) || ! isset($desafio['id'], $desafio['ate']) || $desafio['ate'] < now()->getTimestamp()) {
            self::fechar($sessao);

            return null;
        }

        $admin = SuperAdmin::query()->find($desafio['id']);

        if ($admin === null || ! $admin->estaActivo() || ! $admin->hasEnabledTwoFactorAuthentication()) {
            self::fechar($sessao);

            return null;
        }

        return $admin;
    }

    public static function fechar(Session $sessao): void
    {
        $sessao->forget(self::CHAVE);
    }
}
