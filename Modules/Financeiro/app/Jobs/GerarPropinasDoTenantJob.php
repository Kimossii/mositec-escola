<?php

namespace Modules\Financeiro\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Modules\Core\Tenancy\Jobs\ComTenant;
use Modules\Core\Tenancy\Jobs\UnicoPorTenant;
use Modules\Financeiro\Services\RotinaDiariaDePropinasService;
use RuntimeException;

/**
 * Rotina diária de propinas de uma escola (reconciliação + geração automática). Corre no tenant que o despachou (ComTenant) e só um por escola de cada vez (UnicoPorTenant).
 * Idempotente: repetir ou apanhar o que falhou na véspera é seguro, por isso pode tentar de novo.
 */
class GerarPropinasDoTenantJob implements ShouldBeUnique, ShouldQueue
{
    use ComTenant;
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use UnicoPorTenant;

    public int $tries = 3;

    public int $timeout = 1800;

    public int $uniqueFor = 3600;

    /** @return list<int> */
    public function backoff(): array
    {
        return [60, 300];
    }

    public function identificadorUnico(): string
    {
        return 'gerar-propinas';
    }

    public function handle(RotinaDiariaDePropinasService $servico): void
    {
        ['erros' => $erros] = $servico->executar();

        // Falha o job (e volta a tentar) para a falha ficar visível em failed_jobs; o resumo já foi para os logs.
        if ($erros > 0) {
            throw new RuntimeException("{$erros} matrícula(s) com erro nas propinas; consulte os registos.");
        }
    }
}
