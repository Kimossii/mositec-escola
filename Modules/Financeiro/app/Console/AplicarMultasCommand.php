<?php

namespace Modules\Financeiro\Console;

use Illuminate\Console\Command;
use Modules\Core\Tenancy\Console\ParaTodosOsTenants;
use Modules\Core\Tenancy\TenantAtual;
use Modules\Financeiro\Jobs\AplicarMultasDoTenantJob;
use Modules\Financeiro\Services\AplicarMultasService;
use Modules\Core\Traits\LogService;

/**
 * Comando diário de multas por atraso, por tenant (spec Propinas §15.5): aplica os escalões atingidos às propinas
 * em dívida (e às já pagas cujo atraso existiu antes de pagarem). Idempotente por construção; só actua com
 * `multa_activa` ligado. Sem lógica de negócio: delega no serviço.
 */
class AplicarMultasCommand extends Command
{
    use LogService;

    use ParaTodosOsTenants;

    protected $signature = 'financeiro:aplicar-multas {--fila : Despacha um job por escola em vez de correr já}';

    protected $description = 'Aplica as multas por atraso às propinas em dívida das escolas com multas activas (--tenant=CODIGO ou --todos)';

    public function handle(AplicarMultasService $aplicacao): int
    {
        return $this->paraTenants(function (TenantAtual $tenant) use ($aplicacao): void {
            if ($this->option('fila')) {
                dispatch(new AplicarMultasDoTenantJob());
                $this->line("[{$tenant->codigo}] job de multas despachado.");

                return;
            }

            $resultado = $aplicacao->executar();

            $this->logInfo('financeiro.aplicar-multas', ['tenant' => $tenant->codigo] + $resultado);
            $this->line("[{$tenant->codigo}] {$resultado['criadas']} multa(s) criada(s), "
                . "{$resultado['escaloes_subidos']} escalão(ões) subido(s), {$resultado['ignoradas']} propina(s) ignorada(s).");
        });
    }
}
