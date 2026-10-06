<?php

namespace Modules\Tenant\Console;

use Illuminate\Console\Command;
use Modules\Tenant\Actions\DefinirDominioPrincipalAction;
use Modules\Tenant\Exceptions\OperacaoDeTenantRecusada;
use Modules\Tenant\Services\TenantConsultaService;

/** Chama DefinirDominioPrincipalAction. Sem lógica. */
class DefinirDominioPrincipalCommand extends Command
{
    protected $signature = 'mosi:tenant:domain:principal
        {codigo : Código do tenant (MOSI-000000)}
        {dominio : Domínio (já existente na escola) que passa a principal}';

    protected $description = 'Define qual dos domínios de uma escola é o principal';

    public function handle(TenantConsultaService $consulta, DefinirDominioPrincipalAction $action): int
    {
        try {
            $tenant = $consulta->porCodigo((string) $this->argument('codigo'));
            $dominio = $action->executar($tenant, (string) $this->argument('dominio'));
        } catch (OperacaoDeTenantRecusada $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("O domínio {$dominio->dominio} é agora o principal do tenant {$tenant->codigo}.");

        return self::SUCCESS;
    }
}
