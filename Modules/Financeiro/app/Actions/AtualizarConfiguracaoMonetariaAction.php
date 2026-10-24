<?php

namespace Modules\Financeiro\Actions;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Financeiro\DTO\ConfiguracaoMonetariaDTO;
use Modules\Financeiro\Models\ConfiguracaoMonetaria;
use Modules\Financeiro\Services\MoedaDoTenant;

class AtualizarConfiguracaoMonetariaAction
{
    public function __construct(private MoedaDoTenant $moedaDoTenant)
    {
    }

    public function executar(ConfiguracaoMonetariaDTO $dto): ConfiguracaoMonetaria
    {
        return DB::transaction(function () use ($dto) {
            $configuracao = $this->moedaDoTenant->configuracao();

            if ($configuracao->moeda !== $dto->moeda && ! $this->moedaDoTenant->podeAlterar()) {
                throw ValidationException::withMessages([
                    'moeda' => 'Não é possível alterar a moeda: já existem preços configurados ou registos financeiros.',
                ]);
            }

            $configuracao->update(['moeda' => $dto->moeda, 'cambio_manual' => $dto->cambioManual]);

            return $configuracao->fresh();
        });
    }
}
