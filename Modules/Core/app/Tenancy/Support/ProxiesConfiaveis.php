<?php

namespace Modules\Core\Tenancy\Support;

/**
 * Lê a variável `TRUSTED_PROXIES` (proxies em que a aplicação confia para saber o IP e o esquema, http ou https, do cliente).
 * Vazia ou ausente = não confia em nenhum (o comportamento por omissão e o mais seguro). `*` = todos (só atrás de um proxy
 * da plataforma de alojamento). Ou uma lista de IPs e redes CIDR separados por vírgulas (ex.: `10.0.0.0/8,172.16.0.5`).
 */
class ProxiesConfiaveis
{
    /** @return list<string>|string|null `null` = não confiar em ninguém */
    public static function dasVariaveis(?string $valor): array|string|null
    {
        $valor = trim((string) $valor);

        if ($valor === '') {
            return null;
        }

        if ($valor === '*') {
            return '*';
        }

        $lista = array_values(array_filter(array_map('trim', explode(',', $valor)), fn (string $p) => $p !== ''));

        return $lista === [] ? null : $lista;
    }
}
