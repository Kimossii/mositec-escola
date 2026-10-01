<?php

namespace Modules\Autenticacao\Console;

use Illuminate\Console\Command;
use Modules\Core\Tenancy\Console\ParaTodosOsTenants;
use Modules\Permissao\Actions\SincronizarPerfisDeSistemaAction;
use Modules\Usuario\Actions\CriarTiposDocumentoPadraoAction;

/**
 * Repõe, por tenant, os perfis de sistema com as suas permissões e os tipos de documento padrão.
 * Idempotente. Sem lógica: delega nas Actions do provisioning.
 */
class SincronizarPerfisCommand extends Command
{
    use ParaTodosOsTenants;

    protected $signature = 'mosi:tenant:sync-perfis';

    protected $description = 'Re-sincroniza perfis de sistema, permissões e tipos de documento padrão (--tenant=CODIGO ou --todos)';

    public function handle(SincronizarPerfisDeSistemaAction $perfis, CriarTiposDocumentoPadraoAction $tipos): int
    {
        return $this->paraTenants(function () use ($perfis, $tipos) {
            $perfis->executar();
            $tipos->executar();
        });
    }
}
