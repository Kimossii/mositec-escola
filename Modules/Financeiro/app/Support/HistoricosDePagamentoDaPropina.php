<?php

namespace Modules\Financeiro\Support;

use Illuminate\Contracts\Container\Container;
use LogicException;
use Modules\Financeiro\Contracts\HistoricoDePagamentosDaPropina;
use Modules\Financeiro\Models\Propina;

/**
 * Agrega as fontes de histórico de pagamentos etiquetadas: uma propina tem histórico se alguma disser
 * que sim. Sem fontes (antes de Pagamentos-A), nenhuma tem.
 */
class HistoricosDePagamentoDaPropina
{
    public const ETIQUETA = 'financeiro.historicos-pagamento-propina';

    public function __construct(private Container $container)
    {
    }

    public function temHistorico(Propina $propina): bool
    {
        foreach ($this->container->tagged(self::ETIQUETA) as $fonte) {
            if (! $fonte instanceof HistoricoDePagamentosDaPropina) {
                throw new LogicException('O serviço registado na etiqueta ' . self::ETIQUETA . ' (' . get_debug_type($fonte) . ') tem de implementar ' . HistoricoDePagamentosDaPropina::class . '.');
            }

            if ($fonte->temHistorico($propina)) {
                return true;
            }
        }

        return false;
    }
}
