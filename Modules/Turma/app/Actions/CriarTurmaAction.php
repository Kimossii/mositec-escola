<?php

namespace Modules\Turma\Actions;

use Modules\Turma\DTO\TurmaDTO;
use Modules\Turma\Models\Turma;
use Modules\Core\Traits\LogService;

class CriarTurmaAction
{
    use LogService;

    /** Regista a operação (conclusão ou falha) e delega em `executarSemRegisto()`. */
    public function executar(TurmaDTO $dto): Turma
    {
        return $this->comLog('Criar turma', $this->contextoDeArgumentos(func_get_args(), 'executar'), fn () => $this->executarSemRegisto($dto));
    }

    private function executarSemRegisto(TurmaDTO $dto): Turma
    {
        return Turma::create([
            'ano_lectivo_id' => $dto->ano_lectivo_id,
            'nivel_academico_id' => $dto->nivel_academico_id,
            'curso_id' => $dto->curso_id,
            'codigo' => $dto->codigo,
            'nome' => $dto->nome,
            'turno_id' => $dto->turno_id,
        ]);
    }
}
