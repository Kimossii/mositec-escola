<?php

namespace Modules\Financeiro\Actions;

use Illuminate\Validation\ValidationException;
use Modules\Financeiro\Models\Produto;
use Modules\Financeiro\Support\ReferenciasFinanceiras;

class EliminarProdutoAction
{
    public function __construct(private ReferenciasFinanceiras $referencias)
    {
    }

    public function executar(Produto $produto): void
    {
        if ($this->referencias->existeReferenciaA($produto)) {
            throw ValidationException::withMessages([
                'eliminar' => 'Não é possível eliminar este produto: já existem registos financeiros que o utilizam. Desative-o.',
            ]);
        }

        $produto->delete();
    }
}
