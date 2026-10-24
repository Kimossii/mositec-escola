<?php

namespace Modules\Financeiro\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use LogicException;
use Modules\Financeiro\Models\Propina;
use Modules\Financeiro\Support\BloqueioDePropinas;
use Modules\Financeiro\Support\CancelamentoDePropinas;

/**
 * Quando um mês passa a isento (pausa de cobrança «não cobra»), cancela as propinas desse mês que ainda
 * se podem cancelar (Em Aberto, sem pagamentos nem histórico) pelo fluxo de cancelamento existente. As
 * que já têm pagamentos ficam e são devolvidas em `mantidas` para o resultado da operação. Idempotente.
 */
class CancelarPropinasDeMesesIsentos
{
    public const MOTIVO = 'Pausa de cobrança: mês isento.';

    public function __construct(
        private BloqueioDePropinas $bloqueio,
        private CancelamentoDePropinas $cancelamento,
    ) {
    }

    /**
     * Na transacção do chamador.
     *
     * @param  list<array{ano: int, mes: int}>  $mesesIsentos
     * @return array{canceladas: list<int>, mantidas: list<int>}
     */
    public function executar(int $anoLectivoId, array $mesesIsentos, ?int $utilizadorId): array
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Cancelar propinas de meses isentos exige uma transacção aberta.');
        }

        if ($mesesIsentos === []) {
            return ['canceladas' => [], 'mantidas' => []];
        }

        $ids = [];

        foreach ($mesesIsentos as $mes) {
            $inicio = sprintf('%04d-%02d-01', $mes['ano'], $mes['mes']);
            $fim = CarbonImmutable::createFromFormat('!Y-m-d', $inicio)->endOfMonth()->toDateString();

            foreach (Propina::query()
                ->activas()
                ->where('ano_lectivo_id', $anoLectivoId)
                ->where('periodo_inicio', '<=', $fim)
                ->where('periodo_fim', '>=', $inicio)
                ->pluck('id')
                ->map(fn ($id) => (int) $id) as $id) {
                $ids[$id] = $id;
            }
        }

        $canceladas = [];
        $mantidas = [];

        foreach ($this->bloqueio->bloquear(array_values($ids)) as $propina) {
            if ($this->cancelamento->motivoParaNaoCancelar($propina) === null) {
                $this->cancelamento->cancelar($propina, self::MOTIVO, $utilizadorId);
                $canceladas[] = (int) $propina->id;
            } else {
                $mantidas[] = (int) $propina->id;
            }
        }

        return ['canceladas' => $canceladas, 'mantidas' => $mantidas];
    }
}
