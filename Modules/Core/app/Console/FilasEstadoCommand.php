<?php

namespace Modules\Core\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Diz se a fila tem quem a processe. Em `sync` os jobs correm no próprio pedido (nada a vigiar); em `database` ficam
 * parados se não houver `queue:work`. Sinal de worker parado: jobs à espera há mais de 5 minutos. Sai com erro nesse caso.
 */
class FilasEstadoCommand extends Command
{
    private const MINUTOS_PARA_ALERTA = 5;

    protected $signature = 'mosi:filas:estado';

    protected $description = 'Mostra o modo da fila, os jobs em espera e falhados, e avisa se parece não haver worker a correr';

    public function handle(): int
    {
        $ligacao = (string) config('queue.default');

        if ($ligacao === 'sync') {
            $this->info('Fila em modo sync: os jobs correm no próprio pedido, não é preciso worker.');

            return self::SUCCESS;
        }

        if ($ligacao !== 'database') {
            $this->line("Fila «{$ligacao}»: verifique o estado no próprio serviço.");

            return self::SUCCESS;
        }

        $espera = DB::table('jobs')->count();
        $maisAntigo = DB::table('jobs')->min('available_at');
        $falhados = DB::table('failed_jobs')->count();
        $minutos = $maisAntigo === null ? 0 : (int) floor((time() - (int) $maisAntigo) / 60);

        $this->line("Fila «database»: {$espera} job(s) em espera, {$falhados} falhado(s).");

        if ($espera > 0 && $minutos >= self::MINUTOS_PARA_ALERTA) {
            $this->error("Há jobs à espera há {$minutos} min: o worker (`php artisan queue:work`) parece parado.");

            return self::FAILURE;
        }

        if ($falhados > 0) {
            $this->warn('Há jobs falhados: `php artisan queue:failed` para ver, `queue:retry all` para repetir.');
        }

        return self::SUCCESS;
    }
}
