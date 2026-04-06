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
        Role::insert([
            ['nome' => 0, 'descricao' => 'Aluno'],
            ['nome' => 1, 'descricao' => 'Administrador'],
            ['nome' => 2, 'descricao' => 'Professor'],
            ['nome' => 3, 'descricao' => 'Secretario'],
        ]);
    }
}
