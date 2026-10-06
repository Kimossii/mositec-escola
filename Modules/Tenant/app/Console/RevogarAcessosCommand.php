<?php

namespace Modules\Tenant\Console;

use Illuminate\Console\Command;
use Modules\Tenant\Actions\RevogarAcessosDeEscolaSuspensaAction;
use Modules\Tenant\Exceptions\OperacaoDeTenantRecusada;
use Modules\Tenant\Services\TenantConsultaService;

/** Chama RevogarAcessosDeEscolaSuspensaAction. Sem lógica. */
class RevogarAcessosCommand extends Command
{
    protected $signature = 'mosi:tenant:revogar-acessos {codigo : Código do tenant (MOSI-000000)}';

    protected $description = 'Termina as sessões e os tokens de API de uma escola já suspensa';

    public function handle(TenantConsultaService $consulta, RevogarAcessosDeEscolaSuspensaAction $action): int
    {
        try {
            $tenant = $consulta->porCodigo((string) $this->argument('codigo'));
            $revogados = $action->executar($tenant);
        } catch (OperacaoDeTenantRecusada $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("{$revogados['sessoes']} sessão(ões) e {$revogados['tokens']} token(s) revogado(s) no tenant {$tenant->codigo}.");

        return self::SUCCESS;
    }
}
