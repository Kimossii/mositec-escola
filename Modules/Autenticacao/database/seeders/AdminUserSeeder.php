<?php

namespace Modules\Autenticacao\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Modules\Permissao\Enums\Perfil;
use Modules\Permissao\Models\Role;
use Modules\Usuario\Enums\TipoLogin;
use Modules\Usuario\Models\User;

/**
 * Administrador de DESENVOLVIMENTO do tenant corrente, com senha conhecida e sem troca obrigatória,
 * para os testes visuais locais. Só corre em desenvolvimento/testes: em produção o administrador
 * nasce no provisioning (mosi:tenant:create), com senha temporária aleatória.
 * Se o utilizador já existe (criado pelo provisioning), fica com a senha de desenvolvimento.
 */
class AdminUserSeeder extends Seeder
{
    public const SENHA_DESENVOLVIMENTO = '12345678';

    public function run(string $email = 'admin@mositec.gmail.com', string $nome = 'Administrador MosiTec'): void
    {
        if (! app()->environment('local', 'testing')) {
            return;
        }

        $admin = User::updateOrCreate(
            ['email' => $email],
            [
                'name' => $nome,
                'password' => Hash::make(self::SENHA_DESENVOLVIMENTO),
                'tipo_login' => TipoLogin::EMAIL,
                'estado' => 1,
            ]
        );
        $admin->forceFill(['deve_alterar_senha' => false])->save();

        $roleAdminEscola = Role::where('nome', Perfil::ADMIN_ESCOLA->value)->firstOrFail();
        $admin->roles()->syncWithoutDetaching([$roleAdminEscola->id]);
    }
}
