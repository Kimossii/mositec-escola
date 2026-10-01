<?php

namespace Modules\Tenant\Console;

use Illuminate\Console\Command;
use Modules\Tenant\Actions\EncerrarTenantAction;
use Modules\Tenant\Exceptions\OperacaoDeTenantRecusada;
use Modules\Tenant\Services\TenantConsultaService;

/** Pede confirmação (salvo --force) e chama EncerrarTenantAction. Sem lógica. */
class EncerrarTenantCommand extends Command
{
    protected $signature = 'mosi:tenant:close
        {codigo : Código do tenant (MOSI-000000)}
        {--force : Encerra sem pedir confirmação}';

    protected $description = 'Encerra uma escola (tenant): estado terminal, os dados ficam retidos';

    public function handle(TenantConsultaService $consulta, EncerrarTenantAction $action): int
    {
        try {
            $tenant = $consulta->porCodigo((string) $this->argument('codigo'));

            if (! $this->option('force') && ! $this->confirm("Encerrar o tenant {$tenant->codigo} ({$tenant->nome})? Não pode ser reaberto por este comando.", false)) {
                $this->warn('Operação cancelada; nada foi alterado.');

                return self::FAILURE;
            }

            $action->executar($tenant);
        } catch (OperacaoDeTenantRecusada $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("Tenant {$tenant->codigo} encerrado.");

        return self::SUCCESS;
    }
}
