<?php

namespace Modules\Financeiro\Actions;

use Modules\Financeiro\DTO\CambioDTO;
use Modules\Financeiro\Models\Cambio;
use Modules\Financeiro\Services\CambioDoDia;
use Modules\Financeiro\Services\MoedaDoTenant;

class RegistarCambioAction
{
    public function __construct(private MoedaDoTenant $moedaDoTenant)
    {
    }

    /**
     * Uma linha por dia: repetir o dia actualiza a taxa.
     */
    public function executar(CambioDTO $dto): Cambio
    {
        return Cambio::updateOrCreate(
            [
                'moeda_cotada' => $this->moedaDoTenant->atual()->codigo,
                'moeda_base' => CambioDoDia::REFERENCIA,
                'data' => $dto->data,
            ],
            ['taxa' => $dto->taxa->micros()],
        );
    }
}
