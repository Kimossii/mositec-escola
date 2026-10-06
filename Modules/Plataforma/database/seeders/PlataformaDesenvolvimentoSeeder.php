<?php

namespace Modules\Plataforma\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Modules\Plataforma\Models\SuperAdmin;

/**
 * Super admin de DESENVOLVIMENTO, com senha conhecida e sem troca obrigatória, para os testes
 * visuais locais. Só corre em desenvolvimento/testes: em produção o primeiro super admin nasce
 * com `mosi:plataforma:admin:create` (senha temporária aleatória). Idempotente.
 */
class PlataformaDesenvolvimentoSeeder extends Seeder
{
    public const EMAIL = 'plataforma@mositec.test';

    public const SENHA_DESENVOLVIMENTO = '12345678';

    public function run(): void
    {
        if (! app()->environment('local', 'testing')) {
            return;
        }

        $admin = SuperAdmin::updateOrCreate(
            ['email' => self::EMAIL],
            [
                'name' => 'Super Admin MosiTec',
                'password' => Hash::make(self::SENHA_DESENVOLVIMENTO),
                'estado' => 1,
            ]
        );
        $admin->forceFill(['deve_alterar_senha' => false])->save();
    }
}
