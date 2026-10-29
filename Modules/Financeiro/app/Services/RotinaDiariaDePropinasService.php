<?php

namespace Modules\Financeiro\Services;

use Modules\Core\Tenancy\TenantContext;
use Modules\Core\Traits\LogService;

/**
 * Rotina diária de propinas do tenant corrente (usada pelo comando e pelo job):
 * 1. Reconciliação dos cancelamentos por estado da matrícula (D12 de F3): SEMPRE, mesmo com a geração automática desligada.
 * 2. Geração automática (spec §7.6): só com «gerar automaticamente» ligado. Idempotente.
 */
class RotinaDiariaDePropinasService
{
    use LogService;

    public function __construct(private GerarPropinasService $geracao, private ReconciliarCancelamentosDePropinas $reconciliacao)
    {
    }

    /**
     * O que correu bem já está gravado; quem chama decide o que fazer com os erros (a execução seguinte volta a tentar).
     *
     * @return array{linhas: list<string>, erros: int}
     */
    public function executar(): array
    {
        $codigo = app(TenantContext::class)->atual()->codigo;
        $linhas = [];

        $reconciliados = $this->reconciliacao->executar();
        $this->logInfo('financeiro.reconciliar-cancelamentos', ['tenant' => $codigo] + $reconciliados);

        if ($reconciliados['canceladas'] > 0 || $reconciliados['erros'] > 0) {
            $linhas[] = "[{$codigo}] reconciliação: {$reconciliados['canceladas']} propina(s) cancelada(s) em "
                . "{$reconciliados['matriculas']} matrícula(s) terminada(s), {$reconciliados['erros']} erro(s).";
        }

        $erros = $reconciliados['erros'];
        $resultados = $this->geracao->gerarAutomaticamente();

        if ($resultados === null) {
            $this->logInfo('financeiro.gerar-propinas', ['tenant' => $codigo, 'activa' => false]);
            $linhas[] = "[{$codigo}] geração automática desligada nas Regras de Cobrança: nada a fazer.";
        } else {
            foreach ($resultados as $anoLectivoId => $resultado) {
                $totais = $resultado->totais();
                $erros += $totais['erros'];

                $this->logInfo('financeiro.gerar-propinas', ['tenant' => $codigo, 'activa' => true, 'ano_lectivo_id' => $anoLectivoId] + $totais);
                $linhas[] = "[{$codigo}] ano lectivo {$anoLectivoId}: {$totais['matriculas']} matrícula(s), {$totais['criadas']} propina(s) criada(s), "
                    . "{$totais['ignoradas']} ignorada(s), {$totais['conflitos']} conflito(s), {$totais['erros']} erro(s).";
            }
        }

        return ['linhas' => $linhas, 'erros' => $erros];
    }
}
