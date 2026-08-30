<?php

namespace Modules\Autenticacao\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Modules\Permissao\Enums\Perfil;
use Modules\Permissao\Models\Role;
use Modules\Usuario\Enums\TipoLogin;
use Modules\Usuario\Models\User;

class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        $admin = User::updateOrCreate(
            ['email' => 'admin@mositec.gmail.com'],
            [
                'name' => 'Administrador MosiTec',
                'password' => Hash::make('12345678'),
                'tipo_login' => TipoLogin::EMAIL,
                'estado' => 1,
            ]
        );

        $roleAdminEscola = Role::where('nome', Perfil::ADMIN_ESCOLA->value)->firstOrFail();
        $admin->roles()->syncWithoutDetaching([$roleAdminEscola->id]);
    }
}
