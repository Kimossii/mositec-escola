<?php

namespace Modules\AnoLectivo\Actions;

use Modules\AnoLectivo\Actions\Concerns\ValidaIntervaloEvento;
use Modules\AnoLectivo\DTO\EventoCalendarioDTO;
use Modules\AnoLectivo\Models\AnoLectivo;
use Modules\AnoLectivo\Models\EventoCalendario;

class CriarEventoCalendarioAction
{
    use ValidaIntervaloEvento;

    public function criar(AnoLectivo $anoLectivo, EventoCalendarioDTO $dto): EventoCalendario
    {
        $this->garantirDentroDoIntervalo($anoLectivo, $dto->dataInicio, $dto->dataFim);

        return $anoLectivo->eventosCalendario()->create([
            'titulo' => $dto->titulo,
            'descricao' => $dto->descricao,
            'tipo' => $dto->tipo->value,
            'data_inicio' => $dto->dataInicio,
            'data_fim' => $dto->dataFim,
            'dia_inteiro' => $dto->diaInteiro,
        ]);
    }
}
