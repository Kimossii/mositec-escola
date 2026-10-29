<?php

namespace Modules\Core\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Manutenção das exportações em fila (central, todas as escolas):
 * - pedidos «a preparar» há mais de 30 min (worker parado) passam a «Falhou»;
 * - ficheiros com mais de 7 dias (finais e temporários, incluindo órfãos de utilizadores eliminados) e os seus pedidos são apagados.
 */
class LimparExportacoesCommand extends Command
{
    private const MINUTOS_PRESO = 30;

    private const DIAS_DE_VIDA = 7;

    protected $signature = 'exportacoes:limpar';

    protected $description = 'Marca como falhadas as exportações presas e apaga os ficheiros com mais de 7 dias';

    public function handle(): int
    {
        $disco = Storage::disk('local');

        $presos = DB::table('pedidos_exportacao')->where('estado', 0)->where('created_at', '<', now()->subMinutes(self::MINUTOS_PRESO));
        foreach ((clone $presos)->pluck('id') as $id) {
            $disco->delete("exportacoes/pendentes/{$id}.json");
        }
        $presos = $presos->update(['estado' => 2, 'erro' => 'A exportação não chegou a ser preparada. Peça-a de novo.', 'concluido_em' => now()]);

        $limite = now()->subDays(self::DIAS_DE_VIDA)->getTimestamp();
        $ficheiros = 0;
        foreach ($disco->allFiles('exportacoes') as $ficheiro) {
            if ($disco->lastModified($ficheiro) < $limite) {
                $disco->delete($ficheiro);
                $ficheiros++;
            }
        }

        $apagados = DB::table('pedidos_exportacao')->where('created_at', '<', now()->subDays(self::DIAS_DE_VIDA))->delete();

        $this->info("{$presos} pedido(s) preso(s) marcado(s) como falhado(s), {$ficheiros} ficheiro(s) e {$apagados} pedido(s) antigo(s) apagado(s).");

        return self::SUCCESS;
    }
}
