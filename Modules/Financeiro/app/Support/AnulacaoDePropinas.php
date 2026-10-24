<?php

namespace Modules\Financeiro\Support;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;
use Modules\Estabelecimento\Services\RelogioDoTenant;
use Modules\Financeiro\DTO\MotivoDeAnulacaoDTO;
use Modules\Financeiro\Enums\EstadoCobranca;
use Modules\Financeiro\Models\Propina;

/**
 * Ponto ÚNICO de escrita do estado Anulada de uma propina e de `motivo_anulacao`, `anulado_por`, `anulado_em`
 * (AnuladoresDePropinaTest). Cancelar ≠ anular (spec Propinas §8; §8.2/2 do plano de fases): anula-se só uma propina
 * persistida Em Aberto, sem valor pago, sem contribuições activas e COM histórico (todos os pagamentos e utilizações
 * de crédito que a tocaram já anulados). Sem histórico, cancela-se.
 */
class AnulacaoDePropinas
{
    public function __construct(
        private FontesDePagamentoDePropina $fontes,
        private HistoricosDePagamentoDaPropina $historicos,
        private RelogioDoTenant $relogio,
        private AnulacaoDeMultas $multas,
    ) {
    }

    /** null = anulável. Com a propina bloqueada pelo chamador é definitivo; sem bloqueio é indicativo (UI). */
    public function motivoParaNaoAnular(Propina $propina): ?string
    {
        return match (true) {
            $propina->estado === EstadoCobranca::ANULADA => 'A propina já está anulada.',
            $propina->estado === EstadoCobranca::CANCELADA => 'Uma propina cancelada não pode ser anulada.',
            $propina->estado !== EstadoCobranca::EM_ABERTO,
            $propina->valorRecebido()->unidadesMenores() > 0,
            $this->fontes->contribuicoes($propina) !== [] => 'A propina tem pagamentos ou créditos activos: anule primeiro os pagamentos e as utilizações de crédito que a liquidam.',
            ! $this->historicos->temHistorico($propina) => 'A propina nunca recebeu pagamentos: cancele-a em vez de a anular.',
            $this->multas->temPagamentos($propina) => 'A multa desta propina já recebeu pagamentos: anule primeiro os pagamentos da multa.',
            default => null,
        };
    }

    /** Anula uma propina JÁ BLOQUEADA pelo chamador. Nunca apanhar a excepção dentro da transacção. */
    public function anular(Propina $bloqueada, string $motivo, ?int $utilizadorId): Propina
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Anular uma propina exige uma transacção aberta e a propina bloqueada pelo chamador.');
        }

        $motivo = trim($motivo);
        $tamanho = mb_strlen($motivo);

        if ($tamanho < MotivoDeAnulacaoDTO::MINIMO || $tamanho > MotivoDeAnulacaoDTO::MAXIMO) {
            throw new InvalidArgumentException('O motivo da anulação tem de ter entre ' . MotivoDeAnulacaoDTO::MINIMO . ' e ' . MotivoDeAnulacaoDTO::MAXIMO . ' caracteres.');
        }

        if (($razao = $this->motivoParaNaoAnular($bloqueada)) !== null) {
            throw new LogicException($razao);
        }

        $bloqueada->forceFill([
            'estado' => EstadoCobranca::ANULADA,
            'motivo_anulacao' => $motivo,
            'anulado_por' => $utilizadorId,
            'anulado_em' => $this->relogio->agora()->setTimezone(config('app.timezone')),
        ])->save();

        // F5: a multa da propina (se existir e não tiver pagamentos) passa a Anulada aqui, na mesma transacção.
        $this->multas->anular($bloqueada);

        return $bloqueada;
    }
}
