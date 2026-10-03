<?php

namespace Modules\Plataforma\Support;

use Illuminate\Contracts\Session\Session;
use Modules\Plataforma\Models\SuperAdmin;

/**
 * Liga uma sessão da Plataforma à credencial (hash da senha) do Super Admin que a abriu.
 *
 * Porquê: as sessões da Plataforma têm `user_id` nulo (o guard por omissão é `web`) e o id de um
 * Super Admin pode coincidir com o de um utilizador de escola, por isso uma sessão nunca se
 * invalida apagando linhas de `sessions` por `user_id`. Em vez disso a sessão guarda uma
 * impressão da senha actual (HMAC com a chave da app: o hash da senha nunca vai para a sessão)
 * e o middleware SuperAdminActivo recusa a sessão quando a impressão deixa de coincidir.
 *
 * Mudou a senha (troca própria, reset por comando, qualquer outra via)? Todas as sessões abertas
 * com a senha anterior deixam de valer; a sessão que fez a troca regista a nova impressão.
 *
 * É o ÚNICO sítio com esta lógica: nenhum controller a conhece.
 */
final class ImpressaoDeCredencial
{
    public const CHAVE = 'plataforma.impressao';

    public static function para(SuperAdmin $admin): string
    {
        return hash_hmac('sha256', (string) $admin->password, (string) config('app.key'));
    }

    public static function registar(Session $sessao, SuperAdmin $admin): void
    {
        $sessao->put(self::CHAVE, self::para($admin));
    }

    public static function coincide(Session $sessao, SuperAdmin $admin): bool
    {
        $guardada = $sessao->get(self::CHAVE);

        return is_string($guardada) && hash_equals(self::para($admin), $guardada);
    }
}
