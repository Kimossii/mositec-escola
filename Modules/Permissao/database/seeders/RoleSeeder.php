<?php

namespace Modules\Permissao\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Permissao\Enums\Perfil;
use Modules\Permissao\Models\Role;

class RoleSeeder extends Seeder
{
    public function run(): void
    {
        foreach (Perfil::cases() as $perfil) {
            Role::updateOrCreate(
                ['nome' => $perfil->value],
                ['descricao' => $perfil->label()],
            );
        }
    }
}
