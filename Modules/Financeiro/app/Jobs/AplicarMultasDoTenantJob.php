<?php

namespace Modules\Financeiro\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Modules\Core\Tenancy\Jobs\ComTenant;
use Modules\Core\Tenancy\Jobs\UnicoPorTenant;
use Modules\Core\Tenancy\TenantContext;
use Modules\Core\Traits\LogService;
use Modules\Financeiro\Services\AplicarMultasService;

/**
 * Multas por atraso de uma escola (só actua com multas activas). Corre no tenant que o despachou (ComTenant) e só um por escola de cada vez (UnicoPorTenant).
 * Idempotente: repetir ou apanhar o que falhou na véspera é seguro, por isso pode tentar de novo.
 */
class AplicarMultasDoTenantJob implements ShouldBeUnique, ShouldQueue
{
    use ComTenant;
    use LogService;
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
        return 'aplicar-multas';
    }

    public function handle(AplicarMultasService $servico): void
    {
        $resultado = $servico->executar();

        $this->logInfo('financeiro.aplicar-multas', ['tenant' => app(TenantContext::class)->atual()->codigo] + $resultado);
    }
}
