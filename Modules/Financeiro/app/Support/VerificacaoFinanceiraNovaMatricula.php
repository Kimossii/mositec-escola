<?php

namespace Modules\Financeiro\Support;

use Modules\AnoLectivo\Models\AnoLectivo;
use Modules\Core\Contracts\VerificaNovaMatricula;
use Modules\Core\DTO\ResultadoVerificacaoTrancamento;
use Modules\Financeiro\Enums\EstadoCobranca;
use Modules\Financeiro\Enums\EstadoMulta;
use Modules\Financeiro\Models\Propina;
use Modules\Financeiro\Models\PropinaMulta;
use Modules\Financeiro\Models\RegraCobranca;
use Modules\Matricula\Models\Matricula;

/**
 * Não deixa matricular um aluno que ainda deve propinas ou multas de matrículas de anos lectivos ANTERIORES ao
 * ano pedido. Configurável por escola (`exigir_regularizacao_matricula`, ligado por omissão).
 */
class VerificacaoFinanceiraNovaMatricula implements VerificaNovaMatricula
{
    public function verificar(int $alunoId, int $anoLectivoId): ResultadoVerificacaoTrancamento
    {
        if (! (RegraCobranca::query()->first()?->exigir_regularizacao_matricula ?? true)) {
            return new ResultadoVerificacaoTrancamento();
        }

        $inicioDoAno = AnoLectivo::query()->whereKey($anoLectivoId)->value('data_inicio');

        if ($inicioDoAno === null) {
            return new ResultadoVerificacaoTrancamento();
        }

        $anteriores = Matricula::query()
            ->where('aluno_id', $alunoId)
            ->whereHas('anoLectivo', fn ($ano) => $ano->whereDate('data_inicio', '<', $inicioDoAno))
            ->select('id');

        $capital = (int) Propina::query()
            ->whereIn('matricula_id', $anteriores)
            ->whereIn('estado', [EstadoCobranca::EM_ABERTO->value, EstadoCobranca::PARCIALMENTE_PAGA->value])
            ->selectRaw('COALESCE(SUM(valor - valor_pago), 0) as total')->value('total');

        $multas = (int) PropinaMulta::query()
            ->whereIn('estado', [EstadoMulta::ACTIVA->value, EstadoMulta::REDUZIDA->value])
            ->whereIn('propina_id', Propina::query()->whereIn('matricula_id', $anteriores)->select('id'))
            ->selectRaw('COALESCE(SUM(valor - valor_pago), 0) as total')->value('total');

        if ($capital + $multas <= 0) {
            return new ResultadoVerificacaoTrancamento();
        }

        return new ResultadoVerificacaoTrancamento([[
            'mensagem' => 'O aluno tem dívida de anos lectivos anteriores por regularizar (propinas e multas). Regularize-a antes de o matricular.',
            'detalhe' => ['capital' => $capital, 'multas' => $multas, 'total' => $capital + $multas],
        ]]);
    }
}
