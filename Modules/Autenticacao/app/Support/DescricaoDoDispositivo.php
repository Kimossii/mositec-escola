<?php

namespace Modules\Autenticacao\Support;

/**
 * Lê o `user_agent` de uma sessão e devolve algo legível («Chrome em Windows»). Reconhecimento simples e suficiente
 * para o utilizador identificar as suas sessões; o que não se reconhece fica como «Navegador desconhecido».
 */
class DescricaoDoDispositivo
{
    /** @return array{navegador: string, sistema: string, tipo: string} tipo: `computador` ou `telemovel` */
    public static function de(?string $userAgent): array
    {
        $ua = (string) $userAgent;

        $navegador = match (true) {
            str_contains($ua, 'Edg/') => 'Edge',
            str_contains($ua, 'OPR/') || str_contains($ua, 'Opera') => 'Opera',
            str_contains($ua, 'Firefox/') => 'Firefox',
            str_contains($ua, 'Chrome/') => 'Chrome',
            str_contains($ua, 'Safari/') => 'Safari',
            default => 'Navegador desconhecido',
        };

        $sistema = match (true) {
            str_contains($ua, 'Windows') => 'Windows',
            str_contains($ua, 'Android') => 'Android',
            str_contains($ua, 'iPhone') || str_contains($ua, 'iPad') => 'iOS',
            str_contains($ua, 'Mac OS X') => 'macOS',
            str_contains($ua, 'Linux') => 'Linux',
            default => 'sistema desconhecido',
        };

        return [
            'navegador' => $navegador,
            'sistema' => $sistema,
            'tipo' => in_array($sistema, ['Android', 'iOS'], true) ? 'telemovel' : 'computador',
        ];
    }
}
