<?php

namespace Modules\Financeiro\Support;

use Illuminate\Database\Eloquent\Model;
use Modules\Financeiro\Contracts\ReferenciaFinanceira;
use Modules\Financeiro\Models\Cobranca;
use Modules\Financeiro\Models\CobrancaItem;
use Modules\Financeiro\Models\ConfiguracaoMonetaria;
use Modules\Financeiro\Models\Produto;
use Modules\Financeiro\Models\Servico;

/** Cobranças são registos operacionais: um produto ou serviço vendido não se elimina e a moeda não muda com cobranças. */
class CobrancasReferenciam implements ReferenciaFinanceira
{
    public function existeReferenciaA(Model $configuracao): bool
    {
        return match (true) {
            $configuracao instanceof Produto => CobrancaItem::query()->where('produto_id', $configuracao->getKey())->exists(),
            $configuracao instanceof Servico => CobrancaItem::query()->where('servico_id', $configuracao->getKey())->exists(),
            $configuracao instanceof ConfiguracaoMonetaria => Cobranca::query()->exists(),
            default => false,
        };
    }
}
