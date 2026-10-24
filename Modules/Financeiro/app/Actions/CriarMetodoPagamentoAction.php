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
            'titular' => $dto->titular,
            'banco' => $dto->banco,
            'iban' => $dto->iban,
            'numero_conta' => $dto->numeroConta,
            'telefone' => $dto->telefone,
            'exige_comprovativo' => $dto->exigeComprovativo,
        ]);
    }
}
