<?php

namespace Modules\Turma\Actions;

use Modules\Turma\DTO\TurmaDTO;
use Modules\Turma\Models\Turma;
use Modules\Core\Traits\LogService;

class AtualizarTurmaAction
{
    use LogService;

    /** Regista a operação (conclusão ou falha) e delega em `executarSemRegisto()`. */
    public function executar(
        Turma $turma,
        TurmaDTO $dto
    ): Turma
    {
        return $this->comLog('Atualizar turma', $this->contextoDeArgumentos(func_get_args(), 'executar'), fn () => $this->executarSemRegisto($turma, $dto));
    }

    private function executarSemRegisto(
        Turma $turma,
        TurmaDTO $dto
    ): Turma {
        $turma->fill([
            'nivel_academico_id' => $dto->nivel_academico_id,
            'curso_id' => $dto->curso_id,
            'codigo' => $dto->codigo,
            'nome' => $dto->nome,
            'turno_id' => $dto->turno_id,
        ]);

        $turma->save();

        return $turma->fresh();
    }
}
