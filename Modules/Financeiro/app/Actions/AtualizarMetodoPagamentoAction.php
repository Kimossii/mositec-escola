<?php

namespace Modules\Financeiro\Actions;

use Modules\Financeiro\DTO\MetodoPagamentoDTO;
use Modules\Financeiro\Models\MetodoPagamento;

class AtualizarMetodoPagamentoAction
{
    public function executar(MetodoPagamento $metodo, MetodoPagamentoDTO $dto): MetodoPagamento
    {
        $metodo->update([
            'nome' => $dto->nome,
            'tipo' => $dto->tipo,
        ]);

        return $metodo->fresh();
    }
}
