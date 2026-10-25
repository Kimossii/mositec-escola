<?php

namespace Modules\Turma\Actions;

use Modules\Turma\DTO\TurmaSalaDTO;
use Modules\Turma\Models\TurmaSala;
use Modules\Core\Traits\LogService;

class AtualizarSalaTurmaAction
{
    use LogService;

    /** Regista a operação (conclusão ou falha) e delega em `executarSemRegisto()`. */
    public function executar(
        TurmaSala $turmaSala,
        TurmaSalaDTO $dto
    ): TurmaSala
    {
        return $this->comLog('Atualizar sala turma', $this->contextoDeArgumentos(func_get_args(), 'executar'), fn () => $this->executarSemRegisto($turmaSala, $dto));
    }

    private function executarSemRegisto(
        TurmaSala $turmaSala,
        TurmaSalaDTO $dto
    ): TurmaSala {
        $turmaSala->update([
            'sala_id' => $dto->sala_id,
            'inicio' => $dto->inicio,
        ]);

        return $turmaSala->fresh();
    }
}
