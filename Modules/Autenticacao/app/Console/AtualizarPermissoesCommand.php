<?php

namespace Modules\Autenticacao\Console;

use Illuminate\Console\Command;
use Modules\Core\Tenancy\Console\ParaTodosOsTenants;
use Modules\Permissao\Actions\SincronizarPerfisDeSistemaAction;
use Modules\Permissao\Database\Seeders\AcaoSeeder;

/**
 * Passo único de deploy para acções novas: repõe o catálogo global de acções e re-sincroniza os perfis
 * (--tenant=CODIGO ou --todos). Idempotente.
 */
class AtualizarPermissoesCommand extends Command
{
    use ParaTodosOsTenants;

    protected $signature = 'mosi:permissoes:atualizar';

    protected $description = 'Repõe o catálogo de acções e sincroniza os perfis das escolas (usar após acrescentar uma acção; --tenant=CODIGO ou --todos)';

    public function handle(SincronizarPerfisDeSistemaAction $perfis): int
    {
        $this->call('db:seed', ['--class' => AcaoSeeder::class, '--force' => true]);

        return $this->paraTenants(fn () => $perfis->executar());
    }
}
