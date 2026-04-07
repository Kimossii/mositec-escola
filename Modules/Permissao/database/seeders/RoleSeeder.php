<?php

namespace Modules\Permissao\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Permissao\Models\Role;

class RoleSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // $this->call([]);
        Role::truncate();
        $now = now();
        Role::insert([
            ['nome' => 0, 'descricao' => 'Aluno', 'created_at' => $now, 'updated_at' => $now],
            ['nome' => 1, 'descricao' => 'Administrador', 'created_at' => $now, 'updated_at' => $now],
            ['nome' => 2, 'descricao' => 'Professor', 'created_at' => $now, 'updated_at' => $now],
            ['nome' => 3, 'descricao' => 'Secretario', 'created_at' => $now, 'updated_at' => $now],
        ]);
    }
}
