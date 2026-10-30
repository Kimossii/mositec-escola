<?php

namespace Modules\Financeiro\Support;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use LogicException;
use Modules\Financeiro\Models\Propina;

/**
 * Ponto único de bloqueio de propinas (RecalcularPropina, Pagamentos, alteração de preço, multas):
 * sempre por id crescente, para que duas operações concorrentes nunca se bloqueiem em ordens opostas.
 * SQLite ignora FOR UPDATE; o efeito real só existe em PostgreSQL.
 */
class BloqueioDePropinas
{
    /**
     * @param  list<int>  $ids
     * @return Collection<int, Propina>
     */
    public function bloquear(array $ids): Collection
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Bloquear propinas exige uma transacção aberta (DB::transaction) do chamador.');
        }

        $ids = array_values(array_unique(array_map('intval', $ids)));
        sort($ids);

        if ($ids === []) {
            return new Collection();
        }

        // A multa vai já: a distribuição lê-a por propina (multaPendente), e sem isto seria uma query por propina.
        return Propina::query()->whereKey($ids)->with('multa')->orderBy('id')->lockForUpdate()->get();
    }
}
