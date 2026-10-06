<?php

namespace Modules\Infraestrutura\Actions;

use Modules\Infraestrutura\DTO\SalaDTO;
use Modules\Infraestrutura\Models\Sala;

class AtualizarSalaAction
{
    public function atualizar(Sala $sala, SalaDTO $dto): Sala
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
