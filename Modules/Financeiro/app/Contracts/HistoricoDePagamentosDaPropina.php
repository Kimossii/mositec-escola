<?php

namespace Modules\Financeiro\Contracts;

use Modules\Financeiro\Models\Propina;

/**
 * Se uma propina alguma vez recebeu dinheiro (spec §8; "cancelar ≠ anular", §8.2/2 do plano de fases):
 * qualquer distribuição de pagamento ou utilização de crédito que a tenha tocado, em QUALQUER estado
 * (confirmada, anulada, revertida). Com histórico, uma propina já não se cancela: anula-se (Pagamentos-A).
 * Implementado por Pagamentos; até lá não há implementações e nenhuma propina tem histórico.
 * Registo: $this->app->tag([Classe::class], HistoricosDePagamentoDaPropina::ETIQUETA).
 */
interface HistoricoDePagamentosDaPropina
{
    public function temHistorico(Propina $propina): bool;
}
