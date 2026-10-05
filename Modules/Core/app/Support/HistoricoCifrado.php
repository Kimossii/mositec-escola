<?php

namespace Modules\Core\Support;

use Illuminate\Http\Request;

/**
 * Decide se a resposta pode pedir ao Inertia que cifre o histórico do browser.
 *
 * A cifra usa `crypto.subtle`, só disponível em contexto seguro (HTTPS). Num contexto inseguro o
 * Inertia lança "Unable to encrypt history", a promessa nunca resolve e a visita fica pendurada
 * (o utilizador vê "Aguarde..." para sempre, com a operação já feita no servidor). A cifra só se pede
 * quando há HTTPS: o pedido é seguro, ou `session.secure` está ligado (requisito de produção, que
 * cobre o caso de um proxy a terminar o TLS sem proxies confiáveis configurados).
 */
final class HistoricoCifrado
{
    public static function possivel(Request $request): bool
    {
        return $request->isSecure() || (bool) config('session.secure');
    }
}
