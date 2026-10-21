<?php

namespace Modules\Financeiro\Actions;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Estabelecimento\Services\RelogioDoTenant;
use Modules\Financeiro\Enums\EstadoCobranca;
use Modules\Financeiro\Enums\EstadoPagamento;
use Modules\Financeiro\Models\Cobranca;
use Modules\Financeiro\Models\CobrancaItem;
use Modules\Financeiro\Models\PagamentoCobranca;
use Modules\Financeiro\Models\Produto;
use Modules\Financeiro\Models\ProdutoVariante;
use Modules\Financeiro\Support\BloqueioDePagamentos;

/** Cancela uma cobrança sem pagamentos confirmados e repõe o stock que a venda abateu. */
class CancelarCobrancaAction
{
    public function __construct(private BloqueioDePagamentos $bloqueio, private RelogioDoTenant $relogio)
    {
    }

    public function executar(Cobranca $cobranca, string $motivo, ?int $utilizadorId): Cobranca
    {
        return DB::transaction(function () use ($cobranca, $motivo, $utilizadorId) {
            $this->bloqueio->matricula((int) $cobranca->matricula_id, incluirEliminadas: true);
            $bloqueada = Cobranca::query()->whereKey($cobranca->getKey())->lockForUpdate()->firstOrFail();

            if (! in_array($bloqueada->estado, [EstadoCobranca::EM_ABERTO, EstadoCobranca::PARCIALMENTE_PAGA], true)) {
                throw ValidationException::withMessages(['cobranca' => 'Só se cancela uma cobrança em aberto.']);
            }

            $comPagamento = PagamentoCobranca::query()->where('cobranca_id', $bloqueada->id)
                ->whereHas('pagamento', fn ($p) => $p->where('estado', EstadoPagamento::CONFIRMADO->value))->exists();

            if ($comPagamento) {
                throw ValidationException::withMessages(['cobranca' => 'A cobrança tem pagamentos: anule primeiro os pagamentos.']);
            }

            if (mb_strlen(trim($motivo)) < 5) {
                throw ValidationException::withMessages(['motivo' => 'Indique o motivo do cancelamento (mínimo 5 caracteres).']);
            }

            foreach (CobrancaItem::query()->where('cobranca_id', $bloqueada->id)->where('stock_abatido', true)->orderBy('id')->get() as $item) {
                $alvo = $item->variante_id !== null
                    ? ProdutoVariante::query()->lockForUpdate()->find($item->variante_id)
                    : Produto::query()->lockForUpdate()->find($item->produto_id);
                $alvo?->increment('stock_quantidade', $item->quantidade);
            }

            $bloqueada->forceFill([
                'estado' => EstadoCobranca::CANCELADA,
                'cancelamento_motivo' => trim($motivo),
                'cancelamento_utilizador_id' => $utilizadorId,
                'cancelamento_data' => $this->relogio->agora()->setTimezone(config('app.timezone')),
            ])->save();

            return $bloqueada->fresh('itens');
        });
    }
}
