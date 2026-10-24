<?php

namespace Modules\Core\Contracts;

use Carbon\CarbonImmutable;
use Modules\Core\DTO\ResultadoVerificacaoTrancamento;

/**
 * Ponto de extensão para outros módulos (ex.: Financeiro) impedirem ou avisarem antes de trancar uma matrícula,
 * sem que Matricula os importe. Implementações etiquetadas com ETIQUETA; sem implementações nada bloqueia.
 * Corre na transacção de quem tranca, com a matrícula já bloqueada.
 */
interface VerificaTrancamentoMatricula
{
    public const ETIQUETA = 'matricula.verificacoes-trancamento';

    public function verificar(int $matriculaId, CarbonImmutable $dataEfeito): ResultadoVerificacaoTrancamento;
}
