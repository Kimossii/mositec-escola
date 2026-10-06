<?php

namespace Modules\Tenant\Console;

use Illuminate\Console\Command;
use Modules\Tenant\Actions\ReactivarTenantAction;
use Modules\Tenant\Exceptions\OperacaoDeTenantRecusada;
use Modules\Tenant\Services\TenantConsultaService;

/** Chama ReactivarTenantAction. Sem lógica. */
class ReactivarTenantCommand extends Command
{
    protected $signature = 'mosi:tenant:reactivate {codigo : Código do tenant (MOSI-000000)}';

    protected $description = 'Reactiva uma escola (tenant) suspensa';

    public function handle(TenantConsultaService $consulta, ReactivarTenantAction $action): int
    {
        try {
            $tenant = $consulta->porCodigo((string) $this->argument('codigo'));
            $action->executar($tenant);
        } catch (OperacaoDeTenantRecusada $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("Tenant {$tenant->codigo} reactivado.");

        return self::SUCCESS;
    }
}
