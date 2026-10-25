<?php

namespace Modules\Infraestrutura\Actions;

use Modules\Infraestrutura\DTO\SalaDTO;
use Modules\Infraestrutura\Models\Sala;
use Modules\Core\Traits\LogService;

class AtualizarSalaAction
{
    use LogService;

    /** Regista a operação (conclusão ou falha) e delega em `atualizarSemRegisto()`. */
    public function atualizar(Sala $sala, SalaDTO $dto): Sala
    {
        return $this->comLog('Atualizar sala', $this->contextoDeArgumentos(func_get_args(), 'atualizar'), fn () => $this->atualizarSemRegisto($sala, $dto));
    }

    private function atualizarSemRegisto(Sala $sala, SalaDTO $dto): Sala
    {
        $sala->update([
            'codigo' => $dto->codigo,
            'nome' => $dto->nome,
            'tipo' => $dto->tipo->value,
            'capacidade' => $dto->capacidade,
            'localizacao' => $dto->localizacao,
            'observacoes' => $dto->observacoes,
            'estado' => $dto->estado->value,
        ]);

        return $sala->fresh();
    }
}
