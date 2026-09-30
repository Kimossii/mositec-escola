<?php

namespace Modules\Disciplina\Database\Seeders;

use Modules\Disciplina\Database\Seeders\DisciplinaSeeder;
use Illuminate\Database\Seeder;

class DisciplinaDatabaseSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
         $this->call([
             DisciplinaSeeder::class,
         ]);
    }
}
