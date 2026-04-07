<?php

namespace Modules\Permissao\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Permissao\Models\Modulo;

class ModuloSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // $this->call([]);
        Modulo::truncate();
        $now = now();
        Modulo::insert([
            ['nome' => 0, 'descricao' => 'Usuario', 'created_at' => $now, 'updated_at' => $now],
            ['nome' => 1, 'descricao' => 'Autorizacao', 'created_at' => $now, 'updated_at' => $now],
            ['nome' => 2, 'descricao' => 'Ano Letivo', 'created_at' => $now, 'updated_at' => $now],
            ['nome' => 3, 'descricao' => 'Licenca', 'created_at' => $now, 'updated_at' => $now],
            ['nome' => 4, 'descricao' => 'Aluno', 'created_at' => $now, 'updated_at' => $now],
            ['nome' => 5, 'descricao' => 'Professor', 'created_at' => $now, 'updated_at' => $now],
            ['nome' => 6, 'descricao' => 'Turmas', 'created_at' => $now, 'updated_at' => $now],
            ['nome' => 7, 'descricao' => 'Matricula', 'created_at' => $now, 'updated_at' => $now],
            ['nome' => 8, 'descricao' => 'Disciplina', 'created_at' => $now, 'updated_at' => $now],
            ['nome' => 9, 'descricao' => 'Nota', 'created_at' => $now, 'updated_at' => $now],
        ]);
    }
}
