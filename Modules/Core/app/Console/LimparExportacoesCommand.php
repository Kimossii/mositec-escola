<?php

namespace Modules\Core\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Modules\Core\Exportacao\GerarExportacaoJob;
use Modules\Core\Models\Importacao;
use Modules\Core\Models\PedidoExportacao;
use Modules\Core\Tenancy\CaminhoTenant;
use Modules\Core\Tenancy\Console\ParaTodosOsTenants;

/**
 * Manutenção das exportações em fila, escola a escola (--tenant=CODIGO ou --todos):
 * - pedidos «a preparar» há mais de 30 min (worker parado) passam a «Falhou»;
 * - ficheiros com mais de 7 dias (finais e temporários, incluindo órfãos de utilizadores eliminados) e os seus pedidos são apagados.
 */
class LimparExportacoesCommand extends Command
{
    use ParaTodosOsTenants;

    private const MINUTOS_PRESO = 30;

    private const DIAS_DE_VIDA = 7;

    protected $signature = 'exportacoes:limpar';

    protected $description = 'Marca como falhadas as exportações presas e apaga os ficheiros com mais de 7 dias (--tenant=CODIGO ou --todos)';

    public function handle(): int
    {
        return $this->paraTenants(function () {
            $disco = Storage::disk('local');

            $presos = PedidoExportacao::query()->where('estado', PedidoExportacao::PENDENTE)->where('created_at', '<', now()->subMinutes(self::MINUTOS_PRESO));
            foreach ((clone $presos)->pluck('id') as $id) {
                $disco->delete(CaminhoTenant::para(GerarExportacaoJob::PENDENTES."/{$id}.json"));
            }
            $presos = $presos->update(['estado' => PedidoExportacao::ERRO, 'erro' => 'A exportação não chegou a ser preparada. Peça-a de novo.', 'concluido_em' => now()]);

            $limite = now()->subDays(self::DIAS_DE_VIDA)->getTimestamp();
            $ficheiros = 0;
            foreach ($disco->allFiles(CaminhoTenant::para('exportacoes')) as $ficheiro) {
                if ($disco->lastModified($ficheiro) < $limite) {
                    $disco->delete($ficheiro);
                    $ficheiros++;
                }
            }

            // Importações: o ficheiro e as linhas intermédias duram 30 dias (as linhas saem em cascata).
            $antigas = Importacao::query()->where('created_at', '<', now()->subDays(30));
            foreach ((clone $antigas)->pluck('caminho') as $caminho) {
                $disco->delete($caminho);
            }
            $antigas->delete();

            $apagados = PedidoExportacao::query()->where('created_at', '<', now()->subDays(self::DIAS_DE_VIDA))->delete();

            $this->info("{$presos} pedido(s) preso(s) marcado(s) como falhado(s), {$ficheiros} ficheiro(s) e {$apagados} pedido(s) antigo(s) apagado(s).");
        });
    }
}
