<?php

namespace Modules\Curso\Actions;

use Modules\Curso\DTO\CursoDTO;
use Modules\Curso\Models\Curso;
use Modules\Core\Traits\LogService;

class AtualizarCursoAction
{
    use LogService;

    /** Regista a operação (conclusão ou falha) e delega em `executarSemRegisto()`. */
    public function executar(Curso $curso, CursoDTO $dto): Curso
    {
        return $this->comLog('Atualizar curso', $this->contextoDeArgumentos(func_get_args(), 'executar'), fn () => $this->executarSemRegisto($curso, $dto));
    }

    private function executarSemRegisto(Curso $curso, CursoDTO $dto): Curso
    {
        $curso->fill([
            'codigo' => $dto->codigo,
            'nome' => $dto->nome,
            'descricao' => $dto->descricao,
        ]);

        $curso->save();

        return $curso->fresh();
    }
}
