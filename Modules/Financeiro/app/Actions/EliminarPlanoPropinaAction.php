<?php

namespace Modules\Financeiro\Actions;

use Illuminate\Validation\ValidationException;
use Modules\Financeiro\Models\PlanoPropina;
use Modules\Financeiro\Support\ReferenciasFinanceiras;
use Modules\Financeiro\Support\ViolacaoDeChave;

class EliminarPlanoPropinaAction
{
    public function __construct(private ReferenciasFinanceiras $referencias)
    {
    }

    public function executar(PlanoPropina $plano): void
    {
        if ($this->referencias->existeReferenciaA($plano)) {
            throw ValidationException::withMessages([
                'eliminar' => 'Não é possível eliminar este plano de propina: já existem registos financeiros que o utilizam. Desative-o.',
            ]);
        }

        ViolacaoDeChave::comoValidacao(fn () => $plano->delete());
    }
}
