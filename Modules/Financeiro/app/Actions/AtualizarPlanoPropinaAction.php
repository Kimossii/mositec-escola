<?php

namespace Modules\Financeiro\Actions;

use Illuminate\Support\Facades\DB;
use Modules\Financeiro\DTO\PlanoPropinaDTO;
use Modules\Financeiro\Models\PlanoPropina;

class AtualizarPlanoPropinaAction
{
    public function executar(PlanoPropina $plano, PlanoPropinaDTO $dto): PlanoPropina
    {
        return DB::transaction(function () use ($plano, $dto) {
            $plano->update([
                'nome' => $dto->nome,
                'descricao' => $dto->descricao,
                'periodicidade' => $dto->periodicidade,
                'intervalo_meses' => $dto->intervalo_meses,
                'valor' => $dto->valor,
                'mes_inicio' => $dto->mes_inicio,
                'mes_fim' => $dto->mes_fim,
            ]);

            $plano->substituirAlvos($dto->alvos);

            return $plano->fresh('alvos');
        });
    }
}
