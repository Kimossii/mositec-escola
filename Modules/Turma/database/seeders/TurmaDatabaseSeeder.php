<?php

namespace Modules\Turma\Database\Seeders;

use Illuminate\Database\Seeder;

class TurmaDatabaseSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
         $this->call([
            TurnoSeeder::class,
            NivelAcademicoSeeder::class,
            TurmaDadoSeeder::class,
         ]);
    }
}
