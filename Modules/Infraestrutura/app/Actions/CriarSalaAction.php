<?php

namespace Modules\Infraestrutura\Actions;

use Modules\Estabelecimento\Models\Estabelecimento;
use Modules\Infraestrutura\DTO\SalaDTO;
use Modules\Infraestrutura\Models\Sala;

class CriarSalaAction
{
    public function criar(SalaDTO $dto): Sala
    {
        return Sala::create([
            'estabelecimento_id' => Estabelecimento::current()?->id,
            'codigo' => $dto->codigo,
            'nome' => $dto->nome,
            'tipo' => $dto->tipo->value,
            'capacidade' => $dto->capacidade,
            'localizacao' => $dto->localizacao,
            'observacoes' => $dto->observacoes,
            'estado' => $dto->estado->value,
        ]);
    }
}
