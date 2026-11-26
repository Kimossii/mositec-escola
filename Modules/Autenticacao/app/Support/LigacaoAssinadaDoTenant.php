<?php

namespace Modules\Autenticacao\Support;

use Illuminate\Support\Facades\URL;
use Modules\Core\Comunicacao\Email\RemetenteTenant;

/**
 * Gera ligações assinadas e com validade para emails, SEMPRE no domínio principal da escola do contexto corrente.
 * Tem de correr dentro do contexto do tenant (numa notificação em fila, isso já está garantido por ComTenant): o worker não
 * tem pedido HTTP, por isso o domínio vem de RemetenteTenant e não do URL actual.
 */
final class LigacaoAssinadaDoTenant
{
    /** @param  array<string, mixed>  $parametros */
    public static function gerar(string $rota, int $validadeMinutos, array $parametros): string
    {
        URL::forceRootUrl(rtrim(app(RemetenteTenant::class)->url('/'), '/'));

        try {
            return URL::temporarySignedRoute($rota, now()->addMinutes($validadeMinutos), $parametros);
        } finally {
            URL::forceRootUrl(null);
        }
    }
}
