<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Modules\Permissao\Database\Seeders\PermissaoDatabaseSeeder;
use Modules\Usuario\Database\Seeders\UsuarioDatabaseSeeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call([
            PermissaoDatabaseSeeder::class,
            UsuarioDatabaseSeeder::class,
        ]);
       
    }
}
