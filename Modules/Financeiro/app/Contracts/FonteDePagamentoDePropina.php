<?php

namespace Modules\Financeiro\Contracts;

use Modules\Financeiro\Models\Propina;
use Modules\Financeiro\Support\ContribuicaoDePagamento;

/**
 * Quem contribui para o valor pago de uma propina (Pagamentos: distribuições de pagamentos Confirmados
 * e utilizações de crédito Activas). Só RecalcularPropina consulta as fontes. Sem nenhuma registada,
 * o valor pago é 0. Registo: $this->app->tag([Classe::class], FontesDePagamentoDePropina::ETIQUETA).
 *
 * Regras para as fontes e para quem altera contribuições:
 * - cada valor vem na moeda da própria propina e em unidades menores (nunca convertido aqui); a data é
 *   o dia civil efectivo e nunca está no futuro; só entram contribuições activas (>0);
 * - quem cria, confirma, anula ou altera uma contribuição chama RecalcularPropina na MESMA transacção;
 * - antes de tocar em várias propinas, bloqueia-as todas por id crescente com BloqueioDePropinas::bloquear()
 *   (ou usa RecalcularPropina::executarVarias(), que o faz), para duas operações nunca se bloquearem
 *   em ordens opostas.
 */
interface FonteDePagamentoDePropina
{
    /**
     * @return list<ContribuicaoDePagamento>
     */
    public function contribuicoes(Propina $propina): array;
}
