<?php

namespace Modules\Core\Tenancy\Support;

/**
 * Os hosts centrais (`tenancy.hosts_centrais`): sem tenant, é onde vive o painel da Plataforma.
 * Único sítio que lê e normaliza a lista (maiúsculas, porta, ponto final); o ResolverTenant,
 * o ApenasHostCentral e o ValidadorDominio decidem todos por aqui. Lista vazia = nenhum host central.
 */
class HostsCentrais
{
    /** @return string[] */
    public static function lista(): array
    {
        $normalizados = array_map(
            fn ($host) => NormalizadorHost::normalizar((string) $host),
            (array) config('tenancy.hosts_centrais', []),
        );

        return array_values(array_unique(array_filter($normalizados, fn (string $host) => $host !== '')));
    }

    public static function contem(string $host): bool
    {
        $host = NormalizadorHost::normalizar($host);

        return $host !== '' && in_array($host, self::lista(), true);
    }
}
