<?php

namespace Modules\Disciplina\Actions;

use Modules\Disciplina\DTO\DisciplinaDTO;
use Modules\Disciplina\Models\Disciplina;
use Modules\Core\Traits\LogService;

class AtualizarDisciplinaAction
{
    use LogService;

    /** Regista a operação (conclusão ou falha) e delega em `executarSemRegisto()`. */
    public function executar(Disciplina $disciplina, DisciplinaDTO $dto): Disciplina
    {
        return $this->comLog('Atualizar disciplina', $this->contextoDeArgumentos(func_get_args(), 'executar'), fn () => $this->executarSemRegisto($disciplina, $dto));
    }

    private function executarSemRegisto(Disciplina $disciplina, DisciplinaDTO $dto): Disciplina
    {
        $disciplina->fill([
            'codigo' => $dto->codigo,
            'nome' => $dto->nome,
            'descricao' => $dto->descricao,
        ]);

        $disciplina->save();

        return $disciplina->fresh();
    }
}
