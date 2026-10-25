<?php

namespace Modules\AnoLectivo\Actions;

use Modules\AnoLectivo\Actions\Concerns\ValidaIntervaloEvento;
use Modules\AnoLectivo\DTO\EventoCalendarioDTO;
use Modules\AnoLectivo\Models\AnoLectivo;
use Modules\AnoLectivo\Models\EventoCalendario;
use Modules\Core\Traits\LogService;

class CriarEventoCalendarioAction
{
    use LogService;

    use ValidaIntervaloEvento;

    /** Regista a operação (conclusão ou falha) e delega em `criarSemRegisto()`. */
    public function criar(AnoLectivo $anoLectivo, EventoCalendarioDTO $dto): EventoCalendario
    {
        return $this->comLog('Criar evento calendário', $this->contextoDeArgumentos(func_get_args(), 'criar'), fn () => $this->criarSemRegisto($anoLectivo, $dto));
    }

    private function criarSemRegisto(AnoLectivo $anoLectivo, EventoCalendarioDTO $dto): EventoCalendario
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
