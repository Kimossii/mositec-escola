<?php

namespace Modules\Financeiro\Services;

use Carbon\CarbonInterface;

/**
 * Moeda e câmbio a copiar para um registo operacional no momento em que nasce (contrato de snapshot do
 * spec de Configuração, "Moeda e Câmbio"): a moeda da escola e o câmbio de referência (1 USD = X, em
 * micros de TaxaCambio) do último dia com câmbio até $data, ou null. Nunca bloqueia.
 */
class SnapshotMonetario
{
    public function __construct(
        private MoedaDoTenant $moedaDoTenant,
        private CambioDoDia $cambioDoDia,
    ) {
    }

    /**
     * @return array{moeda: string, cambio_usd: ?int}
     */
    public function em(CarbonInterface $data): array
    {
        return [
            'moeda' => $this->moedaDoTenant->atual()->codigo,
            'cambio_usd' => $this->cambioDoDia->para($data)?->micros(),
        ];
    }
}
