<?php

namespace Modules\Core\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Diz se as filas têm quem as processe (`default` e `pesado`). Em `sync` os jobs correm no próprio pedido (nada a vigiar); em `database` ficam
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

        $falhados = DB::table('failed_jobs')->count();
        $alerta = false;

        // Uma linha por fila: `default` (rápidos) e `pesado` (exportações, importações, rotinas diárias), cada uma com o seu worker.
        foreach (['default' => 'queue:work', 'pesado' => 'queue:work database_pesado --queue=pesado --timeout=3600'] as $fila => $comando) {
            $espera = DB::table('jobs')->where('queue', $fila)->count();
            $maisAntigo = DB::table('jobs')->where('queue', $fila)->min('available_at');
            $minutos = $maisAntigo === null ? 0 : (int) floor((time() - (int) $maisAntigo) / 60);

            $this->line("Fila «{$fila}»: {$espera} job(s) em espera.");

            if ($espera > 0 && $minutos >= self::MINUTOS_PARA_ALERTA) {
                $this->error("Há jobs à espera há {$minutos} min na fila «{$fila}»: o worker (`php artisan {$comando}`) parece parado.");
                $alerta = true;
            }
        }

        $this->line("Falhados: {$falhados}.");

        if ($alerta) {
            return self::FAILURE;
        }

        if ($falhados > 0) {
            $this->warn('Há jobs falhados: `php artisan queue:failed` para ver, `queue:retry all` para repetir.');
        }

        return self::SUCCESS;
    }
}
