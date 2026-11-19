<?php

namespace Modules\Core\Tests\Unit\Saude;

use Spatie\Health\Checks\Checks\DatabaseCheck;
use Spatie\Health\Checks\Checks\QueueCheck;
use Spatie\Health\Facades\Health;
use Tests\TestCase;

/** A configuração dos backups e das verificações de saúde não pode regredir em silêncio. */
class SaudeEBackupsTest extends TestCase
{
    public function test_o_backup_leva_so_os_ficheiros_carregados_e_nunca_o_codigo(): void
    {
        $incluir = config('backup.backup.source.files.include');

        $this->assertSame([storage_path('app/privado'), storage_path('app/documentos'), storage_path('app/public')], $incluir);
        $this->assertNotContains(base_path(), $incluir);
        $this->assertSame([config('database.default')], config('backup.backup.source.databases'));
    }

    public function test_os_avisos_de_sucesso_dos_backups_estao_desligados_e_as_falhas_ligadas(): void
    {
        $n = config('backup.notifications.notifications');

        $this->assertSame([], $n[\Spatie\Backup\Notifications\Notifications\BackupWasSuccessfulNotification::class]);
        $this->assertSame(['mail'], $n[\Spatie\Backup\Notifications\Notifications\BackupHasFailedNotification::class]);
        $this->assertSame(['mail'], $n[\Spatie\Backup\Notifications\Notifications\UnhealthyBackupWasFoundNotification::class]);
    }

    public function test_as_verificacoes_de_saude_estao_registadas_e_sem_tabelas_proprias(): void
    {
        $classes = collect(Health::registeredChecks())->map(fn ($c) => $c::class);

        $this->assertTrue($classes->contains(DatabaseCheck::class));
        $this->assertTrue($classes->contains(QueueCheck::class));
        $this->assertArrayHasKey(\Spatie\Health\ResultStores\CacheHealthResultStore::class, config('health.result_stores'));
        $this->assertArrayNotHasKey(\Spatie\Health\ResultStores\EloquentHealthResultStore::class, config('health.result_stores'));
    }
}
