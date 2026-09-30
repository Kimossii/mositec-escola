<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Autenticacao\Database\Seeders\AdminUserSeeder;
use Modules\Core\Database\Seeders\CoreDatabaseSeeder;
use Modules\Curso\Database\Seeders\CursoDatabaseSeeder;
use Modules\Infraestrutura\Database\Seeders\InfraestruturaDatabaseSeeder;
use Modules\Permissao\Database\Seeders\PermissaoDatabaseSeeder;
use Modules\Disciplina\Database\Seeders\DisciplinaDatabaseSeeder;
use Modules\Turma\Database\Seeders\TurmaDatabaseSeeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call([
            PermissaoDatabaseSeeder::class,
            AdminUserSeeder::class,
           // DisciplinaDatabaseSeeder::class,
           //CursoDatabaseSeeder::class,
           //CoreDatabaseSeeder::class,
           //TurmaDatabaseSeeder::class,
          // InfraestruturaDatabaseSeeder::class,
        ]);
    }
}
