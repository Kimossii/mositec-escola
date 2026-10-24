<?php

namespace Modules\Financeiro\Support;

use Carbon\CarbonImmutable;
use Modules\Core\Contracts\VerificaTrancamentoMatricula;
use Modules\Core\DTO\ResultadoVerificacaoTrancamento;
use Modules\Financeiro\Enums\EstadoCobranca;
use Modules\Financeiro\Enums\EstadoCredito;
use Modules\Financeiro\Enums\EstadoMulta;
use Modules\Financeiro\Models\Cobranca;
use Modules\Financeiro\Models\Credito;
use Modules\Financeiro\Models\Propina;
use Modules\Financeiro\Models\PropinaMulta;
use Modules\Financeiro\Models\RegraCobranca;
use Modules\Financeiro\Services\AplicarMultasService;
use Modules\Matricula\Models\Matricula;

/**
 * Regularização financeira antes de trancar (decisões do dono, Q1–Q4): só a matrícula a trancar. Dívida exigível =
 * propinas Em Aberto/Parcialmente Pagas com período já iniciado na data de efeito + multas activas por liquidar
 * (as que o comando diário ainda não aplicou são aplicadas antes, quando há transacção). Com a exigência activa a
 * dívida BLOQUEIA; desactivada, vira aviso que pede confirmação. O crédito do aluno nunca abate sozinho: só aviso.
 */
class VerificacaoFinanceiraTrancamento implements VerificaTrancamentoMatricula
{
    public function __construct(private AplicarMultasService $multas)
    {
    }

    public function verificar(int $matriculaId, CarbonImmutable $dataEfeito): ResultadoVerificacaoTrancamento
    {
        // Só dentro de uma transacção (trancar de facto): a pré-visualização não escreve.
        if (Propina::query()->getConnection()->transactionLevel() > 0) {
            $this->multas->executarParaMatricula($matriculaId);
        }

        $capital = (int) Propina::query()
            ->where('matricula_id', $matriculaId)
            ->whereIn('estado', [EstadoCobranca::EM_ABERTO->value, EstadoCobranca::PARCIALMENTE_PAGA->value])
            ->where('periodo_inicio', '<=', $dataEfeito->toDateString())
            ->selectRaw('COALESCE(SUM(valor - valor_pago), 0) as total')->value('total');

        $multas = (int) PropinaMulta::query()
            ->whereIn('estado', [EstadoMulta::ACTIVA->value, EstadoMulta::REDUZIDA->value])
            ->whereIn('propina_id', Propina::query()->where('matricula_id', $matriculaId)->select('id'))
            ->selectRaw('COALESCE(SUM(valor - valor_pago), 0) as total')->value('total');

        // Produtos e serviços (cobranças) por pagar desta matrícula contam como dívida.
        $capital += (int) Cobranca::query()
            ->where('matricula_id', $matriculaId)
            ->whereIn('estado', [EstadoCobranca::EM_ABERTO->value, EstadoCobranca::PARCIALMENTE_PAGA->value])
            ->selectRaw('COALESCE(SUM(total - valor_pago), 0) as soma')->value('soma');

        $bloqueios = [];
        $avisos = [];

        if ($capital + $multas > 0) {
            $item = [
                'mensagem' => 'A matrícula tem dívida por regularizar (propinas e multas em aberto).',
                'detalhe' => ['capital' => $capital, 'multas' => $multas, 'total' => $capital + $multas],
            ];

            if (RegraCobranca::query()->first()?->exigir_regularizacao_trancamento ?? true) {
                $bloqueios[] = $item;
            } else {
                $item['mensagem'] = 'A matrícula será trancada com dívida em aberto.';
                $item['detalhe']['confirmar'] = true;
                $avisos[] = $item;
            }
        }

        $alunoId = Matricula::query()->whereKey($matriculaId)->value('aluno_id');
        $credito = $alunoId === null ? 0 : (int) Credito::query()
            ->where('aluno_id', $alunoId)->where('estado', EstadoCredito::DISPONIVEL->value)
            ->selectRaw('COALESCE(SUM(valor - valor_utilizado), 0) as total')->value('total');

        if ($credito > 0) {
            $avisos[] = [
                'mensagem' => 'O aluno tem crédito disponível; use-o para regularizar a dívida se for o caso.',
                'detalhe' => ['credito_disponivel' => $credito],
            ];
        }

        return new ResultadoVerificacaoTrancamento($bloqueios, $avisos);
    }
}
