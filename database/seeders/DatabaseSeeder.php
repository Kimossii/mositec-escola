<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Autenticacao\Database\Seeders\AdminUserSeeder;
use Modules\Permissao\Database\Seeders\PermissaoDatabaseSeeder;
use Modules\Usuario\Database\Seeders\UsuarioDatabaseSeeder;

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
            UsuarioDatabaseSeeder::class,
        ]);
    }
}
