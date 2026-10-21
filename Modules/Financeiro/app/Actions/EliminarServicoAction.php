<?php

namespace Modules\Financeiro\Actions;

use Illuminate\Validation\ValidationException;
use Modules\Financeiro\Models\CatalogoAlvo;
use Modules\Financeiro\Models\Servico;
use Modules\Financeiro\Support\ReferenciasFinanceiras;
use Modules\Financeiro\Support\ViolacaoDeChave;

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

        ViolacaoDeChave::comoValidacao(function () use ($servico) {
            CatalogoAlvo::query()->where('item_tipo', 'servico')->where('item_id', $servico->id)->delete();
            $servico->delete();
        });
    }
}
