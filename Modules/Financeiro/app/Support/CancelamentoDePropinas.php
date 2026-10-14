<?php

namespace Modules\Financeiro\Support;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;
use Modules\Estabelecimento\Services\RelogioDoTenant;
use Modules\Financeiro\Enums\EstadoCobranca;
use Modules\Financeiro\Models\Propina;

/**
 * Ponto ÚNICO de escrita do estado Cancelada e de `motivo_cancelamento`, `cancelado_por` e `cancelado_em`
 * (CanceladoresDePropinaTest). Usado pelo cancelamento manual (CancelarPropinaAction), pelo cancelamento por
 * estado da matrícula (§9) e pela reconciliação diária.
 *
 * Cancelar ≠ anular (spec §8; §8.2/2 do plano de fases): cancela-se só uma propina persistida Em Aberto,
 * sem valor pago, sem contribuições activas e sem NENHUM histórico de pagamentos. Uma propina que já recebeu
 * dinheiro, mesmo que entretanto devolvido/anulado, anula-se (Pagamentos-A).
 */
class CancelamentoDePropinas
{
    public const MOTIVO_MINIMO = 5;

    public const MOTIVO_MAXIMO = 500;

    public function __construct(
        private FontesDePagamentoDePropina $fontes,
        private HistoricosDePagamentoDaPropina $historicos,
        private RelogioDoTenant $relogio,
    ) {
    }

    /**
     * null = cancelável; senão, a razão (mostrada ao utilizador). Só leitura: com a propina bloqueada pelo
     * chamador a resposta é definitiva; sem bloqueio é só indicativa (ex.: botão no detalhe).
     */
    public function motivoParaNaoCancelar(Propina $propina): ?string
    {
        return match (true) {
            $propina->estado === EstadoCobranca::CANCELADA => 'A propina já está cancelada.',
            $propina->estado === EstadoCobranca::ANULADA => 'Uma propina anulada não pode ser cancelada.',
            $propina->estado !== EstadoCobranca::EM_ABERTO,
            $propina->valorRecebido()->unidadesMenores() > 0 => 'A propina tem pagamentos: só se cancela uma propina Em Aberto sem pagamentos. Anule primeiro os pagamentos.',
            $this->fontes->contribuicoes($propina) !== [] => 'A propina tem pagamentos activos: anule-os antes de cancelar.',
            $this->historicos->temHistorico($propina) => 'A propina já recebeu pagamentos (entretanto anulados): não se cancela, anula-se (disponível com o módulo de Pagamentos).',
            default => null,
        };
    }

    /**
     * Cancela uma propina JÁ BLOQUEADA pelo chamador (BloqueioDePropinas, dentro da transacção dele). Lança se
     * não for cancelável: os chamadores verificam antes com motivoParaNaoCancelar() (esta verificação é a
     * defesa em profundidade). Nunca apanhar a excepção dentro da transacção.
     */
    public function cancelar(Propina $bloqueada, string $motivo, ?int $utilizadorId): Propina
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Cancelar uma propina exige uma transacção aberta e a propina bloqueada pelo chamador.');
        }

        $motivo = trim($motivo);
        $tamanho = mb_strlen($motivo);

        if ($tamanho < self::MOTIVO_MINIMO || $tamanho > self::MOTIVO_MAXIMO) {
            throw new InvalidArgumentException('O motivo do cancelamento tem de ter entre ' . self::MOTIVO_MINIMO . ' e ' . self::MOTIVO_MAXIMO . ' caracteres.');
        }

        if (($razao = $this->motivoParaNaoCancelar($bloqueada)) !== null) {
            throw new LogicException($razao);
        }

        $bloqueada->forceFill([
            'estado' => EstadoCobranca::CANCELADA,
            'motivo_cancelamento' => $motivo,
            'cancelado_por' => $utilizadorId,
            // D16: o Eloquent formata sem converter a zona; guarda-se na zona da aplicação, como created_at.
            'cancelado_em' => $this->relogio->agora()->setTimezone(config('app.timezone')),
        ])->save();

        // F5: a multa da propina (se existir e não tiver pagamentos) passa a Anulada aqui, na mesma transacção.

        return $bloqueada;
    }
}
