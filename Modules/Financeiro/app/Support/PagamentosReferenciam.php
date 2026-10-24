<?php

namespace Modules\Financeiro\Support;

use Illuminate\Database\Eloquent\Model;
use Modules\Financeiro\Contracts\ReferenciaFinanceira;
use Modules\Financeiro\Models\ConfiguracaoMonetaria;
use Modules\Financeiro\Models\MetodoPagamento;
use Modules\Financeiro\Models\Pagamento;

/**
 * Pagamentos são registos operacionais: um método com pagamentos (em qualquer estado) não se elimina — desactiva-se
 * (spec Configuração §5.2: métodos inactivos continuam referenciáveis) — e a moeda da escola não muda enquanto
 * existir qualquer pagamento.
 */
class PagamentosReferenciam implements ReferenciaFinanceira
{
    public function existeReferenciaA(Model $configuracao): bool
    {
        return match (true) {
            $configuracao instanceof MetodoPagamento => Pagamento::query()->where('metodo_pagamento_id', $configuracao->getKey())->exists(),
            $configuracao instanceof ConfiguracaoMonetaria => Pagamento::query()->exists(),
            default => false,
        };
    }
}
