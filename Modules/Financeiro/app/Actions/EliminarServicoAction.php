<?php

namespace Modules\Financeiro\Actions;

use Illuminate\Validation\ValidationException;
use Modules\Financeiro\Models\Servico;
use Modules\Financeiro\Support\ReferenciasFinanceiras;

class EliminarServicoAction
{
    public function __construct(private ReferenciasFinanceiras $referencias)
    {
    }

    public function executar(Servico $servico): void
    {
        if ($this->referencias->existeReferenciaA($servico)) {
            throw ValidationException::withMessages([
                'eliminar' => 'Não é possível eliminar este serviço: já existem registos financeiros que o utilizam. Desative-o.',
            ]);
        }

        $servico->delete();
    }
}
