<?php

namespace Modules\AnoLectivo\Actions;

use Modules\AnoLectivo\Actions\Concerns\ValidaIntervaloPeriodo;
use Modules\AnoLectivo\DTO\PeriodoDTO;
use Modules\AnoLectivo\Models\Periodo;
use Modules\Core\Traits\LogService;

class AtualizarPeriodoAction
{
    use LogService;

    use ValidaIntervaloPeriodo;

    /** Regista a operação (conclusão ou falha) e delega em `atualizarSemRegisto()`. */
    public function atualizar(Periodo $periodo, PeriodoDTO $dto): Periodo
    {
        return $this->comLog('Atualizar período', $this->contextoDeArgumentos(func_get_args(), 'atualizar'), fn () => $this->atualizarSemRegisto($periodo, $dto));
    }

    private function atualizarSemRegisto(Periodo $periodo, PeriodoDTO $dto): Periodo
    {
        $anoLectivo = $periodo->anoLectivo;

        $this->garantirDentroDoIntervalo($anoLectivo, $dto->dataInicio, $dto->dataFim);
        $this->garantirSemSobreposicao($anoLectivo, $dto->dataInicio, $dto->dataFim, $periodo->id);

        $periodo->update([
            'nome' => $dto->nome,
            'tipo' => $dto->tipo->value,
            'numero' => $dto->numero,
            'data_inicio' => $dto->dataInicio,
            'data_fim' => $dto->dataFim,
        ]);

        return $periodo->fresh();
    }
}
