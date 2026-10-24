<?php

namespace Modules\Core\Contracts;

use Modules\Core\DTO\ResultadoVerificacaoTrancamento;

/**
 * Ponto de extensão para outros módulos (ex.: Financeiro) impedirem uma nova matrícula do aluno num ano lectivo
 * (ex.: dívida de anos anteriores), sem que Matricula os importe. Implementações etiquetadas com ETIQUETA; sem
 * implementações nada bloqueia. Só os `bloqueios` do resultado impedem; os `avisos` são ignorados aqui.
 */
interface VerificaNovaMatricula
{
    public const ETIQUETA = 'matricula.verificacoes-nova-matricula';

    public function verificar(int $alunoId, int $anoLectivoId): ResultadoVerificacaoTrancamento;
}
