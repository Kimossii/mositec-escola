<?php

namespace Modules\Financeiro\Actions;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Financeiro\DTO\ConfiguracaoMonetariaDTO;
use Modules\Financeiro\Models\ConfiguracaoMonetaria;
use Modules\Financeiro\Services\MoedaDoTenant;
use Modules\Core\Traits\LogService;

class AtualizarConfiguracaoMonetariaAction
{
    use LogService;

    public function __construct(private MoedaDoTenant $moedaDoTenant)
    {
    }

    /** Regista a operação (conclusão ou falha) e delega em `executarSemRegisto()`. */
    public function executar(ConfiguracaoMonetariaDTO $dto): ConfiguracaoMonetaria
    {
        return $this->comLog('Atualizar configuração monetária', $this->contextoDeArgumentos(func_get_args(), 'executar'), fn () => $this->executarSemRegisto($dto));
    }

    private function executarSemRegisto(ConfiguracaoMonetariaDTO $dto): ConfiguracaoMonetaria
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
