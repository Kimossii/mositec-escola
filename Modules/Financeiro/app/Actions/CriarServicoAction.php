<?php

namespace Modules\Financeiro\Actions;

use Modules\Financeiro\DTO\ServicoDTO;
use Modules\Financeiro\Models\Servico;

class CriarServicoAction
{
    public function executar(ServicoDTO $dto): Servico
    {
        return Servico::create([
            'nome' => $dto->nome,
            'descricao' => $dto->descricao,
            'codigo' => $dto->codigo,
            'preco' => $dto->preco,
        ]);
    }
}
