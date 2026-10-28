<?php

namespace Modules\Curso\Actions;

use Modules\Curso\DTO\CursoDTO;
use Modules\Curso\Models\Curso;
use Modules\Estabelecimento\Models\Estabelecimento;
use Modules\Core\Traits\LogService;

class CriarCursoAction
{
    use LogService;

    /** Regista a operação (conclusão ou falha) e delega em `executarSemRegisto()`. */
    public function executar(CursoDTO $dto): Curso
    {
        return $this->comLog('Criar curso', $this->contextoDeArgumentos(func_get_args(), 'executar'), fn () => $this->executarSemRegisto($dto));
    }

    private function executarSemRegisto(CursoDTO $dto): Curso
    {
        return Curso::create([
            'estabelecimento_id' => Estabelecimento::current()?->id,
            'codigo' => $dto->codigo,
            'nome' => $dto->nome,
            'descricao' => $dto->descricao,
        ]);
    }
}
