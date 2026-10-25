<?php

namespace Modules\Turma\Actions;

use Modules\Turma\DTO\AdicionarHorarioTurnoDTO;
use Modules\Turma\Models\Turno;
use Modules\Turma\Models\TurnoHorario;
use Modules\Core\Traits\LogService;

class AdicionarHorarioTurnoAction
{
    use LogService;

    /** Regista a operação (conclusão ou falha) e delega em `executarSemRegisto()`. */
    public function executar(
        Turno $turno,
        AdicionarHorarioTurnoDTO $dto
    ): TurnoHorario
    {
        return $this->comLog('Adicionar horário turno', $this->contextoDeArgumentos(func_get_args(), 'executar'), fn () => $this->executarSemRegisto($turno, $dto));
    }

    private function executarSemRegisto(
        Turno $turno,
        AdicionarHorarioTurnoDTO $dto
    ): TurnoHorario {
        return TurnoHorario::create([
            'turno_id' => $turno->id,
            'horario_id' => $dto->horario_id,
            'ordem' => $dto->ordem,
        ]);
    }
}
