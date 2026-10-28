<?php

namespace Modules\Turma\Actions;

use Modules\Turma\DTO\TurnoDTO;
use Modules\Turma\Models\Turno;
use Modules\Core\Traits\LogService;

class AtualizarTurnoAction
{
    use LogService;

    /** Regista a operação (conclusão ou falha) e delega em `executarSemRegisto()`. */
    public function executar(Turno $turno, TurnoDTO $dto): Turno
    {
        return $this->comLog('Atualizar turno', $this->contextoDeArgumentos(func_get_args(), 'executar'), fn () => $this->executarSemRegisto($turno, $dto));
    }

    private function executarSemRegisto(Turno $turno, TurnoDTO $dto): Turno
    {
        $turno->fill([
            'nome' => $dto->nome,
            'descricao' => $dto->descricao,
        ]);

        $turno->save();

        return $turno->fresh();
    }
}
