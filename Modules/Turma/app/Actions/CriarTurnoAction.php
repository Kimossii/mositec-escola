<?php

namespace Modules\Turma\Actions;

use Modules\Estabelecimento\Models\Estabelecimento;
use Modules\Turma\DTO\TurnoDTO;
use Modules\Turma\Models\Turno;
use Modules\Core\Traits\LogService;

class CriarTurnoAction
{
    use LogService;

    /** Regista a operação (conclusão ou falha) e delega em `executarSemRegisto()`. */
    public function executar(TurnoDTO $dto): Turno
    {
        return $this->comLog('Criar turno', $this->contextoDeArgumentos(func_get_args(), 'executar'), fn () => $this->executarSemRegisto($dto));
    }

    private function executarSemRegisto(TurnoDTO $dto): Turno
    {
        return Turno::create([
            'estabelecimento_id' => Estabelecimento::current()?->id,
            'nome' => $dto->nome,
            'descricao' => $dto->descricao,
        ]);
    }
}
