<?php

namespace Modules\Financeiro\Actions;

use Modules\Financeiro\DTO\CambioDTO;
use Modules\Financeiro\Models\Cambio;
use Modules\Financeiro\Services\CambioDoDia;
use Modules\Financeiro\Services\MoedaDoTenant;
use Modules\Core\Traits\LogService;

class RegistarCambioAction
{
    use LogService;

    public function __construct(private MoedaDoTenant $moedaDoTenant)
    {
    }

    /** Regista a operação (conclusão ou falha) e delega em `executarSemRegisto()`. */
    public function executar(CambioDTO $dto): Cambio
    {
        return $this->comLog('Registar câmbio', $this->contextoDeArgumentos(func_get_args(), 'executar'), fn () => $this->executarSemRegisto($dto));
    }

    /**
     * Uma linha por dia: repetir o dia actualiza a taxa.
     */
    private function executarSemRegisto(CambioDTO $dto): Cambio
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
