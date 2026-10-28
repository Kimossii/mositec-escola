<?php

namespace Modules\Disciplina\Actions;

use Modules\Disciplina\DTO\DisciplinaDTO;
use Modules\Disciplina\Models\Disciplina;
use Modules\Estabelecimento\Models\Estabelecimento;
use Modules\Core\Traits\LogService;

class CriarDisciplinaAction
{
    use LogService;

    /** Regista a operação (conclusão ou falha) e delega em `executarSemRegisto()`. */
    public function executar(DisciplinaDTO $dto): Disciplina
    {
        return $this->comLog('Criar disciplina', $this->contextoDeArgumentos(func_get_args(), 'executar'), fn () => $this->executarSemRegisto($dto));
    }

    private function executarSemRegisto(DisciplinaDTO $dto): Disciplina
    {
        return Disciplina::create([
            'estabelecimento_id' => Estabelecimento::current()?->id,
            'codigo' => $dto->codigo,
            'nome' => $dto->nome,
            'descricao' => $dto->descricao,
        ]);
    }
}
