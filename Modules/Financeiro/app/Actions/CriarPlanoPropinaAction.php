<?php

namespace Modules\Financeiro\Actions;

use Illuminate\Support\Facades\DB;
use Modules\Financeiro\DTO\PlanoPropinaDTO;
use Modules\Financeiro\Models\PlanoPropina;
use Modules\Core\Traits\LogService;

class CriarPlanoPropinaAction
{
    use LogService;

    /** Regista a operação (conclusão ou falha) e delega em `executarSemRegisto()`. */
    public function executar(PlanoPropinaDTO $dto): PlanoPropina
    {
        return $this->comLog('Criar plano propina', $this->contextoDeArgumentos(func_get_args(), 'executar'), fn () => $this->executarSemRegisto($dto));
    }

    private function executarSemRegisto(PlanoPropinaDTO $dto): PlanoPropina
    {
        return DB::transaction(function () use ($dto) {
            $plano = PlanoPropina::create([
                'ano_lectivo_id' => $dto->ano_lectivo_id,
                'nome' => $dto->nome,
                'descricao' => $dto->descricao,
                'periodicidade' => $dto->periodicidade,
                'intervalo_meses' => $dto->intervalo_meses,
                'valor' => $dto->valor,
                'data_inicio' => $dto->data_inicio,
                'data_fim' => $dto->data_fim,
            ]);

            $plano->substituirAlvos($dto->alvos);

            return $plano->fresh('alvos');
        });
    }
}
