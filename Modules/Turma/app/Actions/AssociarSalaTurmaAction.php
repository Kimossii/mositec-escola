<?php

namespace Modules\Turma\Actions;

use Modules\Turma\DTO\TurmaSalaDTO;
use Modules\Turma\Models\Turma;
use Modules\Turma\Models\TurmaSala;
use Modules\Core\Traits\LogService;

class AssociarSalaTurmaAction
{
    use LogService;

    /** Regista a operação (conclusão ou falha) e delega em `executarSemRegisto()`. */
    public function executar(
        Turma $turma,
        TurmaSalaDTO $dto
    ): TurmaSala
    {
        return $this->comLog('Associar sala turma', $this->contextoDeArgumentos(func_get_args(), 'executar'), fn () => $this->executarSemRegisto($turma, $dto));
    }

    private function executarSemRegisto(
        Turma $turma,
        TurmaSalaDTO $dto
    ): TurmaSala {
        return $turma->turmaSalas()->create([
            'sala_id' => $dto->sala_id,
            'inicio' => $dto->inicio,
        ]);
    }
}
