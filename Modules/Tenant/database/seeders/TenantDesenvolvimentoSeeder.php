<?php

namespace Modules\Tenant\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Core\Tenancy\Support\NormalizadorHost;
use Modules\Tenant\Models\Tenant;

/**
 * Tenant para desenvolvimento local, acessível em http://localhost:8000, http://127.0.0.1:8000 e no host de APP_URL.
 * Provisório: é substituído pelo comando mosi:tenant:create quando o provisioning existir.
 */
class TenantDesenvolvimentoSeeder extends Seeder
{
    public function run(): void
    {
        // Nunca em produção: registaria localhost como domínio de um tenant.
        if (! app()->environment('local', 'testing')) {
            return;
        }

        $tenant = Tenant::firstOrCreate(
            ['codigo' => 'MOSI-000001'],
            ['nome' => 'Escola de Desenvolvimento'],
        );

        $tenant->dominios()->firstOrCreate(['dominio' => 'localhost'], ['is_principal' => true]);
        $tenant->dominios()->firstOrCreate(['dominio' => '127.0.0.1']);

        // O host de APP_URL (ex.: mositec-escola.test), quando é diferente dos dois acima.
        $hostDaApp = NormalizadorHost::normalizar((string) parse_url((string) config('app.url'), PHP_URL_HOST));

        if ($hostDaApp !== '') {
            $tenant->dominios()->firstOrCreate(['dominio' => $hostDaApp]);
        }
    }
}
