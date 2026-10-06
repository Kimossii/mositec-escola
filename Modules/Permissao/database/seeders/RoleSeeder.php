<?php

namespace Modules\Permissao\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Permissao\Actions\SincronizarPerfisDeSistemaAction;

class RoleSeeder extends Seeder
{
    public function run(): void
    {
        app(SincronizarPerfisDeSistemaAction::class)->criarPerfis();
    }
}
