<?php

namespace Modules\AnoLectivo\Actions;

use Modules\AnoLectivo\Actions\Concerns\ValidaIntervaloEvento;
use Modules\AnoLectivo\DTO\EventoCalendarioDTO;
use Modules\AnoLectivo\Models\EventoCalendario;
use Modules\Core\Traits\LogService;

class AtualizarEventoCalendarioAction
{
    use LogService;

    use ValidaIntervaloEvento;

    /** Regista a operação (conclusão ou falha) e delega em `atualizarSemRegisto()`. */
    public function atualizar(EventoCalendario $evento, EventoCalendarioDTO $dto): EventoCalendario
    {
        return $this->comLog('Atualizar evento calendário', $this->contextoDeArgumentos(func_get_args(), 'atualizar'), fn () => $this->atualizarSemRegisto($evento, $dto));
    }

    private function atualizarSemRegisto(EventoCalendario $evento, EventoCalendarioDTO $dto): EventoCalendario
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
