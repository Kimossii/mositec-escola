<?php

namespace Modules\Financeiro\Support;

use Modules\Financeiro\Enums\EstadoCobranca;
use Modules\Financeiro\Models\Propina;

/**
 * Propina em memória (sem id) a partir de um item de ResultadoGeracaoMatriculaDTO::criadas(). Ponto único
 * da conversão item → model: usada pela pré-visualização do pagamento e pela estimativa de multa da
 * pré-visualização da geração. Nunca grava nada.
 */
final class PropinaVirtual
{
    /**
     * @param  array<string, mixed>  $item  um item de ResultadoGeracaoMatriculaDTO::criadas()
     */
    public static function deItem(array $item): Propina
    {
        $propina = new Propina();
        $propina->periodo_inicio = $item['periodo_inicio'];
        $propina->periodo_fim = $item['periodo_fim'];
        $propina->ordem = $item['ordem'];
        $propina->valor = Dinheiro::deUnidadesMenores($item['valor']);
        $propina->valor_pago = Dinheiro::deUnidadesMenores(0);
        $propina->estado = EstadoCobranca::EM_ABERTO;
        $propina->data_vencimento = $item['data_vencimento'];
        $propina->dias_tolerancia = $item['dias_tolerancia'];
        $propina->data_limite = $item['data_limite'];

        return $propina;
    }
}
