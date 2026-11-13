<?php

namespace Modules\Core\Tests\Feature\Queue;

use Modules\Core\Exportacao\GerarExportacaoJob;
use Modules\Core\Importacao\Jobs\ImportarImportacaoJob;
use Modules\Core\Importacao\Jobs\ValidarImportacaoJob;
use Modules\Financeiro\Jobs\AplicarMultasDoTenantJob;
use Modules\Financeiro\Jobs\GerarPropinasDoTenantJob;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class FilaPesadaTest extends TestCase
{
    /** @return array<string, array{0: \Closure(): object}> */
    public static function jobsPesados(): array
    {
        return [
            'exportação' => [fn () => new GerarExportacaoJob(1)],
            'validar importação' => [fn () => new ValidarImportacaoJob(1)],
            'importar' => [fn () => new ImportarImportacaoJob(1)],
            'propinas' => [fn () => new GerarPropinasDoTenantJob()],
            'multas' => [fn () => new AplicarMultasDoTenantJob()],
        ];
    }

    #[DataProvider('jobsPesados')]
    public function test_jobs_pesados_vao_para_a_ligacao_pesada(\Closure $criar): void
    {
        config(['queue.ligacao_pesada' => 'database_pesado']);
        $job = $criar();

        $this->assertSame('database_pesado', $job->connection);
        $this->assertSame('pesado', $job->queue);
    }

    public function test_ligacao_pesada_tem_retry_after_acima_dos_timeouts(): void
    {
        $maiorTimeout = max((new ValidarImportacaoJob(1))->timeout, (new GerarPropinasDoTenantJob())->timeout, (new AplicarMultasDoTenantJob())->timeout, (new GerarExportacaoJob(1))->timeout);

        $this->assertGreaterThan($maiorTimeout, config('queue.connections.database_pesado.retry_after'));
        $this->assertSame(config('queue.connections.database.table'), config('queue.connections.database_pesado.table'));
    }

    public function test_em_sync_a_ligacao_pesada_tambem_e_sync(): void
    {
        config(['queue.ligacao_pesada' => 'sync']);

        $job = new GerarPropinasDoTenantJob();

        $this->assertSame('sync', $job->connection);
        $this->assertNull($job->queue);
    }
}
