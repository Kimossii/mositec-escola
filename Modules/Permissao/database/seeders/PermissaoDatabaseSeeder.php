<?php

namespace Modules\Permissao\Database\Seeders;

use Illuminate\Database\Seeder;

class PermissaoDatabaseSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $this->call([
            AcaoSeeder::class,
            RoleSeeder::class,
            ModuloSeeder::class,
            RolePermissaoSeeder::class,
        ]);
    }
}
