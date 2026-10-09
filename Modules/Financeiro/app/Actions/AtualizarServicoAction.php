<?php

namespace Modules\Financeiro\Actions;

use Modules\Financeiro\DTO\ServicoDTO;
use Modules\Financeiro\Models\Servico;

class AtualizarServicoAction
{
    public function executar(Servico $servico, ServicoDTO $dto): Servico
    {
        $servico->update([
            'nome' => $dto->nome,
            'descricao' => $dto->descricao,
            'codigo' => $dto->codigo,
            'preco' => $dto->preco,
        ]);

        return $servico->fresh();
    }
}
