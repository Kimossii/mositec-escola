<?php

namespace Modules\AnoLectivo\Database\Seeders;

use Illuminate\Database\Seeder;

class AnoLectivoSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            AnoLectivoDadosSeeder::class,
            PeriodoSeeder::class,
        ]);
    }
}
