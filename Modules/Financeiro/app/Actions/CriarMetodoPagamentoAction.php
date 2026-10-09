<?php

namespace Modules\Financeiro\Actions;

use Modules\Financeiro\DTO\MetodoPagamentoDTO;
use Modules\Financeiro\Models\MetodoPagamento;

class CriarMetodoPagamentoAction
{
    public function executar(MetodoPagamentoDTO $dto): MetodoPagamento
    {
        return MetodoPagamento::create([
            'nome' => $dto->nome,
            'tipo' => $dto->tipo,
        ]);
    }
}
