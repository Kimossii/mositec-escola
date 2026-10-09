<?php

namespace Modules\Financeiro\Actions;

use Illuminate\Validation\ValidationException;
use Modules\Financeiro\Models\MetodoPagamento;
use Modules\Financeiro\Support\ReferenciasFinanceiras;

class EliminarMetodoPagamentoAction
{
    public function __construct(private ReferenciasFinanceiras $referencias)
    {
    }

    public function executar(MetodoPagamento $metodo): void
    {
        if ($this->referencias->existeReferenciaA($metodo)) {
            throw ValidationException::withMessages([
                'eliminar' => 'Não é possível eliminar este método de pagamento: já existem registos financeiros que o utilizam. Desative-o.',
            ]);
        }

        $metodo->delete();
    }
}
