<?php

namespace Modules\Core\Support;

use Spatie\Health\Checks\Checks\BackupsCheck;
use Spatie\Health\Checks\Checks\DatabaseCheck;
use Spatie\Health\Checks\Checks\DebugModeCheck;
use Spatie\Health\Checks\Checks\QueueCheck;
use Spatie\Health\Checks\Checks\ScheduleCheck;
use Spatie\Health\Checks\Checks\UsedDiskSpaceCheck;
use Spatie\Health\Facades\Health;

/**
 * As verificações de saúde do sistema (spatie/laravel-health). `php artisan health:list` mostra o último resultado.
 * A fila `pesado` (outra ligação) continua a ser vigiada por `mosi:filas:estado`.
 */
class SaudeDoSistema
{
    public static function registar(): void
    {
        $verificacoes = [
            DatabaseCheck::new(),
            DebugModeCheck::new(),
            ScheduleCheck::new(),
            QueueCheck::new()->onQueue('default'),
            UsedDiskSpaceCheck::new()->warnWhenUsedSpaceIsAbovePercentage(70)->failWhenUsedSpaceIsAbovePercentage(90),
        ];

        // Esta verificação lê ficheiros locais: só serve com o disco `backups` local. Com S3 quem avisa é `backup:monitor`.
        if (config('backup.backup.destination.disks.0') === 'backups') {
            $verificacoes[] = BackupsCheck::new()
                ->locatedAt(storage_path('app/backups/*/*.zip'))
                ->youngestBackShouldHaveBeenMadeBefore(now()->subDay());
        }

        Health::checks($verificacoes);
    }
}
