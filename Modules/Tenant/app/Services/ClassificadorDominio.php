<?php

namespace Modules\Tenant\Services;

use Modules\Core\Tenancy\Support\NormalizadorHost;
use Modules\Tenant\Enums\TipoDominio;

/**
 * Decide o TipoDominio: subdomínio de uma raiz MosiTec (config tenancy.dominios_raiz
 * mais o host de APP_URL) ou domínio personalizado. A própria raiz não é subdomínio.
 */
class ClassificadorDominio
{
    public function classificar(string $dominio): TipoDominio
    {
        $host = NormalizadorHost::normalizar($dominio);

        foreach ($this->raizes() as $raiz) {
            if ($raiz !== '' && str_ends_with($host, '.' . $raiz)) {
                return TipoDominio::SUBDOMINIO;
            }
        }

        return TipoDominio::PERSONALIZADO;
    }

    /** @return list<string> */
    private function raizes(): array
    {
        $raizes = array_map([NormalizadorHost::class, 'normalizar'], (array) config('tenancy.dominios_raiz', []));
        $raizes[] = NormalizadorHost::normalizar((string) parse_url((string) config('app.url'), PHP_URL_HOST));

        return array_values(array_unique($raizes));
    }
}
