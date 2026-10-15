<?php

namespace Modules\Financeiro\Support;

use Carbon\CarbonImmutable;
use Modules\Financeiro\Contracts\FonteDePagamentoDePropina;
use Modules\Financeiro\Enums\EstadoPagamento;
use Modules\Financeiro\Enums\EstadoUtilizacaoCredito;
use Modules\Financeiro\Models\CreditoUtilizacao;
use Modules\Financeiro\Models\PagamentoPropina;
use Modules\Financeiro\Models\Propina;

/**
 * O que conta para o valor pago de uma propina (spec Propinas §6, Pagamentos §3): distribuições de pagamentos
 * CONFIRMADOS (data = data_pagamento) e utilizações de crédito ACTIVAS (data = data_utilizacao). Só é lida pelo
 * RecalcularPropina (via FontesDePagamentoDePropina). Ordem estável: data, distribuições antes de utilizações, id.
 */
class PagamentosDaPropina implements FonteDePagamentoDePropina
{
    public function contribuicoes(Propina $propina): array
    {
        $linhas = [];

        $distribuicoes = PagamentoPropina::query()
            ->where('propina_id', $propina->getKey())
            ->whereHas('pagamento', fn ($pagamento) => $pagamento->where('estado', EstadoPagamento::CONFIRMADO->value))
            ->with('pagamento')
            ->orderBy('id')
            ->get();

        foreach ($distribuicoes as $distribuicao) {
            $linhas[] = [$distribuicao->pagamento->data_pagamento->toDateString(), 0, $distribuicao->id, $distribuicao->valor];
        }

        $utilizacoes = CreditoUtilizacao::query()
            ->where('propina_id', $propina->getKey())
            ->where('estado', EstadoUtilizacaoCredito::ACTIVA->value)
            ->orderBy('id')
            ->get();

        foreach ($utilizacoes as $utilizacao) {
            $linhas[] = [$utilizacao->data_utilizacao->toDateString(), 1, $utilizacao->id, $utilizacao->valor];
        }

        usort($linhas, fn (array $a, array $b) => [$a[0], $a[1], $a[2]] <=> [$b[0], $b[1], $b[2]]);

        return array_map(
            fn (array $linha) => new ContribuicaoDePagamento($linha[3], CarbonImmutable::createFromFormat('!Y-m-d', $linha[0])),
            $linhas,
        );
    }
}
