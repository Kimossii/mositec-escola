<?php

namespace Modules\Tenant\Console;

use Illuminate\Console\Command;
use Modules\Tenant\Actions\AdicionarDominioAction;
use Modules\Tenant\Exceptions\DadosDeTenantInvalidos;
use Modules\Tenant\Exceptions\OperacaoDeTenantRecusada;
use Modules\Tenant\Services\TenantConsultaService;

/** Chama AdicionarDominioAction. Sem lógica. */
class AdicionarDominioCommand extends Command
{
    protected $signature = 'mosi:tenant:domain:add
        {codigo : Código do tenant (MOSI-000000)}
        {dominio : Domínio a acrescentar}';

    protected $description = 'Acrescenta um domínio (subdomínio ou personalizado) a uma escola';

    public function handle(TenantConsultaService $consulta, AdicionarDominioAction $action): int
    {
        try {
            $tenant = $consulta->porCodigo((string) $this->argument('codigo'));
            $dominio = $action->executar($tenant, (string) $this->argument('dominio'));
        } catch (DadosDeTenantInvalidos $e) {
            foreach ($e->erros as $erro) {
                $this->error($erro);
            }

            return self::FAILURE;
        } catch (OperacaoDeTenantRecusada $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("Domínio {$dominio->dominio} ({$dominio->tipo->label()}) acrescentado ao tenant {$tenant->codigo}.");

        return self::SUCCESS;
    }
}
