<?php

namespace Modules\Core\Importacao\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Modules\Core\Importacao\RegistoDeImportadores;
use Modules\Core\Models\Importacao;
use Modules\Core\Models\ImportacaoLinha;
use Modules\Core\Tenancy\Jobs\ComTenant;
use Modules\Core\Traits\LogService;
use Modules\Usuario\Models\User;
use Throwable;

/**
 * Cria os registos das linhas válidas, por blocos, sempre pela Action da entidade. Cada linha é uma transacção (registo +
 * marca «importada»): repetir o job ou retomá-lo depois de uma falha nunca duplica. Um só job por importação de cada vez
 * (os blocos são sequenciais: as sequências, como o n.º de matrícula, não admitem concorrência): só há uma cadeia de jobs,
 * iniciada quando a importação passa atomicamente a «a importar», e cada job continua com outro até não restar nada.
 * Não usa ShouldBeUnique: o lock só se liberta no fim do job e impediria o próprio re-despacho.
 */
class ImportarImportacaoJob implements ShouldQueue
{
    use ComTenant;
    use Dispatchable;
    use InteractsWithQueue;
    use LogService;
    use Queueable;

    private const BLOCO = 500;

    private const SEGUNDOS_POR_EXECUCAO = 45;

    public int $tries = 1;

    public int $timeout = 120;

    public function __construct(public int $importacaoId)
    {
    }

    public function handle(RegistoDeImportadores $registo): void
    {
        $importacao = Importacao::findOrFail($this->importacaoId);

        if ($importacao->estado !== Importacao::A_IMPORTAR) {
            return;
        }

        // Autoria e registos em nome de quem confirmou, não do worker.
        Auth::setUser(User::findOrFail($importacao->user_id));
        $importador = $registo->obter($importacao->entidade);
        $inicio = microtime(true);

        while (microtime(true) - $inicio < self::SEGUNDOS_POR_EXECUCAO) {
            $linhas = ImportacaoLinha::where('importacao_id', $importacao->id)->where('estado', ImportacaoLinha::VALIDA)->orderBy('id')->limit(self::BLOCO)->get();

            if ($linhas->isEmpty()) {
                $this->concluir($importacao);

                return;
            }

            foreach ($linhas as $linha) {
                try {
                    DB::transaction(function () use ($importador, $linha) {
                        $id = $importador->criar($linha->dados);
                        $linha->update(['estado' => ImportacaoLinha::IMPORTADA, 'registo_id' => $id]);
                    });
                } catch (Throwable $e) {
                    report($e);
                    $linha->update(['estado' => ImportacaoLinha::ERRO, 'erros' => ['Não foi possível criar este registo. Confirme os dados desta linha.']]);
                }
            }
        }

        // Ficou trabalho: continua noutro job (em fila, outro worker pode pegar; em sync, corre a seguir).
        static::dispatch($this->importacaoId);
    }

    public function failed(Throwable $excecao): void
    {
        report($excecao);
        Importacao::whereKey($this->importacaoId)->update(['estado' => Importacao::FALHADA, 'erro_geral' => 'A importação foi interrompida. O que já entrou ficou gravado; volte a confirmar para continuar.', 'concluido_em' => now()]);
    }

    private function concluir(Importacao $importacao): void
    {
        $totais = ImportacaoLinha::where('importacao_id', $importacao->id)->selectRaw('estado, count(*) as n')->groupBy('estado')->pluck('n', 'estado');

        $importacao->update([
            'estado' => Importacao::CONCLUIDA,
            'importadas' => (int) ($totais[ImportacaoLinha::IMPORTADA] ?? 0),
            'com_erro' => (int) ($totais[ImportacaoLinha::ERRO] ?? 0),
            'concluido_em' => now(),
        ]);
        $this->logInfo('Importação concluída', ['importacao' => $importacao->id, 'entidade' => $importacao->entidade, 'importadas' => $importacao->importadas, 'com_erro' => $importacao->com_erro]);
    }
}
