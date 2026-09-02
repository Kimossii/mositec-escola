<?php

namespace Modules\AnoLectivo\Actions;

use Modules\AnoLectivo\Actions\Concerns\ValidaIntervaloEvento;
use Modules\AnoLectivo\DTO\EventoCalendarioDTO;
use Modules\AnoLectivo\Models\EventoCalendario;

class AtualizarEventoCalendarioAction
{
    use ValidaIntervaloEvento;

    public function atualizar(EventoCalendario $evento, EventoCalendarioDTO $dto): EventoCalendario
    {
        $this->garantirDentroDoIntervalo($evento->anoLectivo, $dto->dataInicio, $dto->dataFim);

        $evento->update([
            'titulo' => $dto->titulo,
            'descricao' => $dto->descricao,
            'tipo' => $dto->tipo->value,
            'data_inicio' => $dto->dataInicio,
            'data_fim' => $dto->dataFim,
            'dia_inteiro' => $dto->diaInteiro,
        ]);

        return $evento->fresh();
    }
}
