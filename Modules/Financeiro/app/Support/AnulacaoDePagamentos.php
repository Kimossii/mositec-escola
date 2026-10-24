<?php

namespace Modules\Financeiro\Support;

use Modules\Financeiro\Enums\EstadoPagamento;
use Modules\Financeiro\Enums\EstadoUtilizacaoCredito;
use Modules\Financeiro\Models\Credito;
use Modules\Financeiro\Models\CreditoUtilizacao;
use Modules\Financeiro\Models\Pagamento;
use Modules\Financeiro\Services\MoedaDoTenant;

/** Regra (só leitura) de quando um pagamento pode ser anulado; partilhada pela Action e pela consulta do detalhe. */
class AnulacaoDePagamentos
{
    public function __construct(private MoedaDoTenant $moedaDoTenant)
    {
    }

    /** null = anulável. Sem bloqueio é só indicativo (botão do detalhe); dentro da Action é definitivo. */
    public function motivoParaNaoAnular(Pagamento $pagamento): ?string
    {
        if ($pagamento->estado === EstadoPagamento::ANULADO) {
            return 'O pagamento já está anulado.';
        }

        $credito = Credito::query()->where('origem_pagamento_id', $pagamento->getKey())->first();
        $usado = $credito === null ? 0 : (int) CreditoUtilizacao::query()
            ->where('credito_id', $credito->id)
            ->where('estado', EstadoUtilizacaoCredito::ACTIVA->value)
            ->sum('valor');

        if ($usado > 0) {
            return 'O crédito gerado por este pagamento já foi utilizado (' . Dinheiro::deUnidadesMenores($usado)->formatar($this->moedaDoTenant->atual())
                . '): anule primeiro essas utilizações de crédito.';
        }

        return null;
    }
}
