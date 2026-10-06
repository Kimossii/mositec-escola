<?php

namespace Modules\Core\Tenancy\Support;

class NormalizadorHost
{
    /**
     * Minúsculas, sem espaços, sem porta e sem ponto final.
     */
    public static function normalizar(string $host): string
    {
        $host = mb_strtolower(trim($host));
        $host = preg_replace('/:\d+$/', '', $host);

        return rtrim($host, '.');
    }
}
