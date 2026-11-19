<?php

namespace Modules\Core\Console;

use Illuminate\Console\Command;
use Modules\Core\Support\EstadoDasFilas;

/**
 * Diz se as filas têm quem as processe (`default` e `pesado`). Em `sync` os jobs correm no próprio pedido (nada a vigiar); em `database` ficam
 * parados se não houver `queue:work`. Sinal de worker parado: jobs à espera há mais de 5 minutos. Sai com erro nesse caso.
 */
class FilasEstadoCommand extends Command
{
    protected $signature = 'mosi:filas:estado';

    protected $description = 'Mostra o modo da fila, os jobs em espera e falhados, e avisa se parece não haver worker a correr';

    public function handle(EstadoDasFilas $estados): int
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

        $estado = $estados->obter();
        $falhados = $estado['falhados'];
        $alerta = false;
        $comandos = ['default' => 'queue:work', 'pesado' => 'queue:work database_pesado --queue=pesado --timeout=3600'];

        // Uma linha por fila: `default` (rápidos) e `pesado` (exportações, importações, rotinas diárias), cada uma com o seu worker.
        foreach ($estado['filas'] as $fila) {
            $this->line("Fila «{$fila['nome']}»: {$fila['em_espera']} job(s) em espera.");

            if ($fila['parada']) {
                $this->error("Há jobs à espera há {$fila['minutos']} min na fila «{$fila['nome']}»: o worker (`php artisan {$comandos[$fila['nome']]}`) parece parado.");
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
