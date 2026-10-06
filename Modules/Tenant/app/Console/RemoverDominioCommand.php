<?php

namespace Modules\Tenant\Console;

use Illuminate\Console\Command;
use Modules\Tenant\Actions\RemoverDominioAction;
use Modules\Tenant\Exceptions\OperacaoDeTenantRecusada;
use Modules\Tenant\Services\TenantConsultaService;

/** Chama RemoverDominioAction. Sem lógica. */
class RemoverDominioCommand extends Command
{
    protected $signature = 'mosi:tenant:domain:remove
        {codigo : Código do tenant (MOSI-000000)}
        {dominio : Domínio a remover}';

    protected $description = 'Remove um domínio secundário de uma escola';

    public function handle(TenantConsultaService $consulta, RemoverDominioAction $action): int
    {
        try {
            $tenant = $consulta->porCodigo((string) $this->argument('codigo'));
            $action->executar($tenant, (string) $this->argument('dominio'));
        } catch (OperacaoDeTenantRecusada $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("Domínio removido do tenant {$tenant->codigo}.");

        return self::SUCCESS;
    }
}
