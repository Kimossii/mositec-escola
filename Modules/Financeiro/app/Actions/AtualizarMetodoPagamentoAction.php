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
            'titular' => $dto->titular,
            'banco' => $dto->banco,
            'iban' => $dto->iban,
            'numero_conta' => $dto->numeroConta,
            'telefone' => $dto->telefone,
            'exige_comprovativo' => $dto->exigeComprovativo,
        ]);

        return $metodo->fresh();
    }
}
