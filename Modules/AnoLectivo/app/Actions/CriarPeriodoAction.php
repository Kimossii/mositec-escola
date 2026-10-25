<?php

namespace Modules\AnoLectivo\Actions;

use Modules\AnoLectivo\Actions\Concerns\ValidaIntervaloPeriodo;
use Modules\AnoLectivo\DTO\PeriodoDTO;
use Modules\AnoLectivo\Models\AnoLectivo;
use Modules\AnoLectivo\Models\Periodo;
use Modules\Core\Traits\LogService;

class CriarPeriodoAction
{
    use LogService;

    use ValidaIntervaloPeriodo;

    /** Regista a operação (conclusão ou falha) e delega em `criarSemRegisto()`. */
    public function criar(AnoLectivo $anoLectivo, PeriodoDTO $dto): Periodo
    {
        return $this->comLog('Criar período', $this->contextoDeArgumentos(func_get_args(), 'criar'), fn () => $this->criarSemRegisto($anoLectivo, $dto));
    }

    private function criarSemRegisto(AnoLectivo $anoLectivo, PeriodoDTO $dto): Periodo
    {
        $this->garantirDentroDoIntervalo($anoLectivo, $dto->dataInicio, $dto->dataFim);
        $this->garantirSemSobreposicao($anoLectivo, $dto->dataInicio, $dto->dataFim);

        return $anoLectivo->periodos()->create([
            'nome' => $dto->nome,
            'tipo' => $dto->tipo->value,
            'numero' => $dto->numero,
            'data_inicio' => $dto->dataInicio,
            'data_fim' => $dto->dataFim,
        ]);
    }
}
