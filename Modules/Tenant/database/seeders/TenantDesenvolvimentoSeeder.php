<?php

namespace Modules\Tenant\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Core\Tenancy\Support\NormalizadorHost;
use Modules\Tenant\Actions\CriarTenantAction;
use Modules\Tenant\DTO\CriarTenantDTO;
use Modules\Tenant\Models\Domain;
use Modules\Tenant\Models\Tenant;

/**
 * Os dois tenants de desenvolvimento, criados pelo mesmo caminho da produção (CriarTenantAction,
 * com os provisionadores de cada módulo). Só corre em desenvolvimento/testes.
 *
 *   MOSI-000001  localhost, 127.0.0.1 e o host de APP_URL  (admin@mositec.gmail.com)
 *   MOSI-000002  escola-b.mositec-escola.test               (admin@escola-b.mositec.test)
 *
 * Os administradores nascem com senha temporária; o AdminUserSeeder, chamado pelo
 * DatabaseSeeder dentro de cada tenant, dá-lhes a senha de desenvolvimento conhecida.
 * Requer o catálogo global (ModuloSeeder e AcaoSeeder) já semeado.
 */
class TenantDesenvolvimentoSeeder extends Seeder
{
    public const TENANTS = [
        [
            'codigo' => 'MOSI-000001',
            'nome' => 'Escola de Desenvolvimento',
            'dominio' => 'localhost',
            'admin_nome' => 'Administrador MosiTec',
            'admin_email' => 'admin@mositec.gmail.com',
        ],
        [
            'codigo' => 'MOSI-000002',
            'nome' => 'Escola B de Desenvolvimento',
            'dominio' => 'escola-b.mositec-escola.test',
            'admin_nome' => 'Administrador Escola B',
            'admin_email' => 'admin@escola-b.mositec.test',
        ],
    ];

    public function run(): void
    {
        // Nunca em produção: registaria localhost como domínio de um tenant.
        if (! app()->environment('local', 'testing')) {
            return;
        }

        foreach (self::TENANTS as $definicao) {
            // Em TENANCY_MODO=unico só cabe um tenant por instalação.
            if ($definicao['codigo'] !== self::TENANTS[0]['codigo'] && config('tenancy.modo') === 'unico') {
                continue;
            }

            $tenant = Tenant::query()->where('codigo', $definicao['codigo'])->first();

            if ($tenant === null) {
                $tenant = app(CriarTenantAction::class)->executar(new CriarTenantDTO(
                    nomeEstabelecimento: $definicao['nome'],
                    nomeAdministrador: $definicao['admin_nome'],
                    emailAdministrador: $definicao['admin_email'],
                    dominioPrincipal: $definicao['dominio'],
                    codigo: $definicao['codigo'],
                ))->tenant;
            }

            foreach ($this->dominiosExtra($definicao['codigo']) as $dominio) {
                if (! Domain::query()->where('dominio', $dominio)->exists()) {
                    $tenant->dominios()->create(['dominio' => $dominio]);
                }
            }
        }
    }

    /** Hosts locais adicionais (só do primeiro tenant). */
    private function dominiosExtra(string $codigo): array
    {
        if ($codigo !== self::TENANTS[0]['codigo']) {
            return [];
        }

        $extra = ['127.0.0.1'];

        // O host de APP_URL (ex.: mositec-escola.test), quando é diferente dos outros.
        $hostDaApp = NormalizadorHost::normalizar((string) parse_url((string) config('app.url'), PHP_URL_HOST));

        if ($hostDaApp !== '' && $hostDaApp !== self::TENANTS[0]['dominio'] && $hostDaApp !== self::TENANTS[1]['dominio']) {
            $extra[] = $hostDaApp;
        }

        return $extra;
    }
}
