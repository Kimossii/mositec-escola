<?php

namespace Modules\Permissao\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Permissao\Actions\SincronizarPerfisDeSistemaAction;

/**
 * A lógica vive na Action, partilhada com o provisionador de tenants.
 */
class RolePermissaoSeeder extends Seeder
{
    public function run(): void
    {
        app(SincronizarPerfisDeSistemaAction::class)->concederPermissoes();
    }
}
