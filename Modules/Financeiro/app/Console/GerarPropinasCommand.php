<?php

namespace Modules\Financeiro\Console;

use Illuminate\Console\Command;
use Modules\Core\Tenancy\Console\ParaTodosOsTenants;
use Modules\Core\Tenancy\TenantAtual;
use Modules\Financeiro\Services\GerarPropinasService;
use Modules\Financeiro\Services\ReconciliarCancelamentosDePropinas;
use RuntimeException;
use Modules\Core\Traits\LogService;

/**
 * Comando diário de propinas, por tenant:
 * 1. Reconciliação dos cancelamentos por estado da matrícula (D12 de F3): SEMPRE, mesmo com a geração automática
 *    desligada — cancelar é protector.
 * 2. Geração automática (spec §7.6): só com «gerar automaticamente» ligado. Idempotente; recupera o que a geração ao activar uma matrícula não fez (§8.2/3).
 * Sem lógica de negócio: delega nos serviços.
 */
class GerarPropinasCommand extends Command
{
    use LogService;

    use ParaTodosOsTenants;

    protected $signature = 'financeiro:gerar-propinas';

    protected $description = 'Reconcilia os cancelamentos e gera as propinas em falta das matrículas activas nas escolas com a geração automática ligada (--tenant=CODIGO ou --todos)';

    public function handle(GerarPropinasService $geracao, ReconciliarCancelamentosDePropinas $reconciliacao): int
    {
        return $this->paraTenants(function (TenantAtual $tenant) use ($geracao, $reconciliacao): void {
            $reconciliados = $reconciliacao->executar();
            $this->logInfo('financeiro.reconciliar-cancelamentos', ['tenant' => $tenant->codigo] + $reconciliados);

            if ($reconciliados['canceladas'] > 0 || $reconciliados['erros'] > 0) {
                $this->line("[{$tenant->codigo}] reconciliação: {$reconciliados['canceladas']} propina(s) cancelada(s) em "
                    . "{$reconciliados['matriculas']} matrícula(s) terminada(s), {$reconciliados['erros']} erro(s).");
            }

            $erros = $reconciliados['erros'] + $this->gerar($geracao, $tenant);

            // Tudo o que correu bem já está gravado. O tenant fica como falhado para o código de saída e o agendador
            // o assinalarem; a execução seguinte volta a tentar (idempotente).
            if ($erros > 0) {
                throw new RuntimeException("{$erros} matrícula(s) com erro nas propinas; consulte os registos.");
            }
        });
    }

    /** @return int matrículas com erro */
    private function gerar(GerarPropinasService $geracao, TenantAtual $tenant): int
    {
        $resultados = $geracao->gerarAutomaticamente();

        if ($resultados === null) {
            $this->logInfo('financeiro.gerar-propinas', ['tenant' => $tenant->codigo, 'activa' => false]);
            $this->line("[{$tenant->codigo}] geração automática desligada nas Regras de Cobrança: nada a fazer.");

            return 0;
        }

        $erros = 0;

        foreach ($resultados as $anoLectivoId => $resultado) {
            $totais = $resultado->totais();
            $erros += $totais['erros'];

            $this->logInfo('financeiro.gerar-propinas', ['tenant' => $tenant->codigo, 'activa' => true, 'ano_lectivo_id' => $anoLectivoId] + $totais);
            $this->line("[{$tenant->codigo}] ano lectivo {$anoLectivoId}: {$totais['matriculas']} matrícula(s), {$totais['criadas']} propina(s) criada(s), "
                . "{$totais['ignoradas']} ignorada(s), {$totais['conflitos']} conflito(s), {$totais['erros']} erro(s).");
        }

        return $erros;
    }
}
