<?php

namespace Modules\Tenant\Services;

use Modules\Core\Tenancy\Support\NormalizadorHost;
use Modules\Tenant\Exceptions\DadosDeTenantInvalidos;
use Modules\Tenant\Models\Domain;

/**
 * Validação de domínios (spec §5.4): normaliza, recusa formato inválido, subdomínios
 * reservados, hosts centrais e duplicados. Partilhada por CriarTenantAction e,
 * mais tarde, AdicionarDominioAction.
 */
class ValidadorDominio
{
    private const FORMATO = '/^(?=.{1,253}$)[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?(\.[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?)*$/';

    /**
     * @return string o domínio normalizado
     *
     * @throws DadosDeTenantInvalidos
     */
    public function validar(string $dominio): string
    {
        $host = NormalizadorHost::normalizar($dominio);

        if ($host === '' || preg_match(self::FORMATO, $host) !== 1) {
            throw new DadosDeTenantInvalidos(['dominio' => "Domínio inválido: '{$dominio}'."]);
        }

        if (! app()->environment('local', 'testing')) {
            $hostDaApp = NormalizadorHost::normalizar((string) parse_url((string) config('app.url'), PHP_URL_HOST));

            if (filter_var($host, FILTER_VALIDATE_IP) !== false || ! str_contains($host, '.') || $host === 'localhost' || $host === $hostDaApp) {
                throw new DadosDeTenantInvalidos(['dominio' => "O domínio '{$host}' não é permitido neste ambiente: use um nome completo (ex.: escola.exemplo.ao), nunca um IP, localhost ou o host da aplicação."]);
            }
        }

        $primeiraEtiqueta = explode('.', $host)[0];

        if (in_array($primeiraEtiqueta, config('tenancy.subdominios_reservados', []), true)) {
            throw new DadosDeTenantInvalidos(['dominio' => "O subdomínio '{$primeiraEtiqueta}' está reservado."]);
        }

        if (in_array($host, array_map([NormalizadorHost::class, 'normalizar'], config('tenancy.hosts_centrais', [])), true)) {
            throw new DadosDeTenantInvalidos(['dominio' => "'{$host}' é um host central e não pode pertencer a um tenant."]);
        }

        if (Domain::query()->where('dominio', $host)->exists()) {
            throw new DadosDeTenantInvalidos(['dominio' => "O domínio '{$host}' já está registado."]);
        }

        return $host;
    }
}
