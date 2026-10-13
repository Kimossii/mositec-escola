<?php

namespace Modules\Financeiro\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Modules\Core\Tenancy\Console\ParaTodosOsTenants;
use Modules\Core\Tenancy\TenantAtual;
use Modules\Financeiro\Services\GerarPropinasService;
use RuntimeException;

/**
 * Geração automática de propinas (spec §7.6): em cada tenant com «gerar automaticamente» ligado, gera as
 * propinas em falta das matrículas Activas, pelo mesmo serviço da geração manual. Idempotente; agendado
 * diariamente com --todos. Sem lógica: delega em GerarPropinasService.
 */
class GerarPropinasCommand extends Command
{
    use ParaTodosOsTenants;

    protected $signature = 'financeiro:gerar-propinas';

    protected $description = 'Gera as propinas em falta das matrículas activas nas escolas com a geração automática ligada (--tenant=CODIGO ou --todos)';

    public function handle(GerarPropinasService $geracao): int
    {
        return $this->paraTenants(function (TenantAtual $tenant) use ($geracao): void {
            $resultados = $geracao->gerarAutomaticamente();

            if ($resultados === null) {
                Log::info('financeiro.gerar-propinas', ['tenant' => $tenant->codigo, 'activa' => false]);
                $this->line("[{$tenant->codigo}] geração automática desligada nas Regras de Cobrança: nada a fazer.");

                return;
            }

            $erros = 0;

            foreach ($resultados as $anoLectivoId => $resultado) {
                $totais = $resultado->totais();
                $erros += $totais['erros'];

                Log::info('financeiro.gerar-propinas', ['tenant' => $tenant->codigo, 'activa' => true, 'ano_lectivo_id' => $anoLectivoId] + $totais);
                $this->line("[{$tenant->codigo}] ano lectivo {$anoLectivoId}: {$totais['matriculas']} matrícula(s), {$totais['criadas']} propina(s) criada(s), "
                    . "{$totais['ignoradas']} ignorada(s), {$totais['conflitos']} conflito(s), {$totais['erros']} erro(s).");
            }

            // As matrículas que correram bem já estão gravadas. O tenant fica como falhado para o código de saída
            // e o agendador o assinalarem; a execução seguinte volta a tentar (idempotente).
            if ($erros > 0) {
                throw new RuntimeException("{$erros} matrícula(s) com erro na geração de propinas; consulte os registos.");
            }
        });
    }
}
