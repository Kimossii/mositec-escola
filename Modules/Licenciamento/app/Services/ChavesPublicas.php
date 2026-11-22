<?php

namespace Modules\Licenciamento\Services;

/** Lê as chaves públicas de config (kid => base64) e entrega-as ao codec em binário (32 bytes). Entradas inválidas são ignoradas. */
class ChavesPublicas
{
    /** @return array<string,string> */
    public static function daConfig(): array
    {
        $chaves = [];

        foreach ((array) config('licenciamento.chaves_publicas', []) as $kid => $base64) {
            if (! is_string($kid) || $kid === '' || ! is_string($base64)) {
                continue;
            }

            $binaria = base64_decode($base64, true);

            if ($binaria !== false && strlen($binaria) === SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
                $chaves[$kid] = $binaria;
            }
        }

        return $chaves;
    }
}
