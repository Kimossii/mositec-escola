<?php

namespace Modules\Financeiro\Console;

use Illuminate\Console\Command;
use Modules\Core\Tenancy\Console\ParaTodosOsTenants;
use Modules\Core\Tenancy\TenantAtual;
use Modules\Financeiro\Jobs\GerarPropinasDoTenantJob;
use Modules\Financeiro\Services\RotinaDiariaDePropinasService;
use RuntimeException;

/**
 * Comando diário de propinas, por tenant. Sem lógica de negócio: delega em RotinaDiariaDePropinasService.
 * Com `--fila` despacha um job por escola (corre em sync ou no worker, conforme QUEUE_CONNECTION); sem ele corre já.
 */
class GerarPropinasCommand extends Command
{
    use ParaTodosOsTenants;

    protected $signature = 'financeiro:gerar-propinas {--fila : Despacha um job por escola em vez de correr já}';

    protected $description = 'Reconcilia os cancelamentos e gera as propinas em falta das matrículas activas nas escolas com a geração automática ligada (--tenant=CODIGO ou --todos)';

    public function handle(RotinaDiariaDePropinasService $rotina): int
    {
        return $this->paraTenants(function (TenantAtual $tenant) use ($rotina): void {
            if ($this->option('fila')) {
                dispatch(new GerarPropinasDoTenantJob());
                $this->line("[{$tenant->codigo}] job de propinas despachado.");

                return;
            }

            ['linhas' => $linhas, 'erros' => $erros] = $rotina->executar();

            foreach ($linhas as $linha) {
                $this->line($linha);
            }

            // O tenant fica como falhado para o código de saída e o agendador o assinalarem.
            if ($erros > 0) {
                throw new RuntimeException("{$erros} matrícula(s) com erro nas propinas; consulte os registos.");
            }
        });
    }
}
