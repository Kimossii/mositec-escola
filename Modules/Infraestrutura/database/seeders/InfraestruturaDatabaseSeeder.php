<?php

namespace Modules\Infraestrutura\Database\Seeders;

use Illuminate\Database\Seeder;

class InfraestruturaDatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            SalaSeeder::class,
        ]);
    }
}
