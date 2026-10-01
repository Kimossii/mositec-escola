<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Autenticacao\Database\Seeders\AdminUserSeeder;
use Modules\Core\Database\Seeders\CoreDatabaseSeeder;
use Modules\Core\Tenancy\TenantContext;
use Modules\Curso\Database\Seeders\CursoDatabaseSeeder;
use Modules\Estabelecimento\Database\Seeders\EstabelecimentoDesenvolvimentoSeeder;
use Modules\Infraestrutura\Database\Seeders\InfraestruturaDatabaseSeeder;
use Modules\Permissao\Database\Seeders\PermissaoDatabaseSeeder;
use Modules\Disciplina\Database\Seeders\DisciplinaDatabaseSeeder;
use Modules\Turma\Database\Seeders\TurmaDatabaseSeeder;
use Modules\Tenant\Database\Seeders\TenantDesenvolvimentoSeeder;
use Modules\Tenant\Models\Tenant;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call(TenantDesenvolvimentoSeeder::class);

        // Tudo o resto é dados de um tenant: corre dentro do contexto do tenant de desenvolvimento.
        $tenant = Tenant::where('codigo', 'MOSI-000001')->firstOrFail();

        app(TenantContext::class)->executarComo($tenant->paraTenantAtual(), function () {
            $this->call([
                EstabelecimentoDesenvolvimentoSeeder::class,
                PermissaoDatabaseSeeder::class,
                AdminUserSeeder::class,
                // DisciplinaDatabaseSeeder::class,
                // CursoDatabaseSeeder::class,
                // CoreDatabaseSeeder::class,
                // TurmaDatabaseSeeder::class,
                // InfraestruturaDatabaseSeeder::class,
            ]);
        });
    }
}
