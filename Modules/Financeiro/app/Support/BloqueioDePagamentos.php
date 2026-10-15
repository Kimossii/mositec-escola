<?php

namespace Modules\Financeiro\Support;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use LogicException;
use Modules\Financeiro\Models\Credito;
use Modules\Financeiro\Models\CreditoUtilizacao;
use Modules\Financeiro\Models\Pagamento;
use Modules\Matricula\Models\Matricula;

/**
 * Bloqueios de Pagamentos pela ordem global (D13): matrícula → pagamento → propinas (BloqueioDePropinas) →
 * créditos (id crescente) → utilização → sequência. Todos FOR UPDATE, dentro da transacção do chamador; devolvem
 * as linhas relidas. SQLite ignora FOR UPDATE; o efeito real só existe em PostgreSQL.
 */
class BloqueioDePagamentos
{
    public function matricula(int $id, bool $incluirEliminadas = false): ?Matricula
    {
        $this->exigirTransaccao();

        return Matricula::query()->when($incluirEliminadas, fn ($q) => $q->withTrashed())->whereKey($id)->lockForUpdate()->first();
    }

    public function pagamento(int $id): ?Pagamento
    {
        $this->exigirTransaccao();

        return Pagamento::query()->whereKey($id)->lockForUpdate()->first();
    }

    /**
     * @param  list<int>  $ids
     * @return Collection<int, Credito>
     */
    public function creditos(array $ids): Collection
    {
        $this->exigirTransaccao();
        $ids = array_values(array_unique(array_map('intval', $ids)));
        sort($ids);

        return $ids === [] ? new Collection() : Credito::query()->whereKey($ids)->orderBy('id')->lockForUpdate()->get();
    }

    public function utilizacao(int $id): ?CreditoUtilizacao
    {
        $this->exigirTransaccao();

        return CreditoUtilizacao::query()->whereKey($id)->lockForUpdate()->first();
    }

    private function exigirTransaccao(): void
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Os bloqueios de Pagamentos exigem uma transacção aberta (DB::transaction) do chamador.');
        }
    }
}
