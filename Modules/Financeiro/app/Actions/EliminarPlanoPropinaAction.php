<?php

namespace Modules\Financeiro\Actions;

use Illuminate\Validation\ValidationException;
use Modules\Financeiro\Models\PlanoPropina;
use Modules\Financeiro\Support\ReferenciasFinanceiras;
use Modules\Financeiro\Support\ViolacaoDeChave;
use Modules\Core\Traits\LogService;

class EliminarPlanoPropinaAction
{
    use LogService;

    public function __construct(private ReferenciasFinanceiras $referencias)
    {
    }

    /** Regista a operação (conclusão ou falha) e delega em `executarSemRegisto()`. */
    public function executar(PlanoPropina $plano): void
    {
        $this->comLog('Eliminar plano propina', $this->contextoDeArgumentos(func_get_args(), 'executar'), fn () => $this->executarSemRegisto($plano));
    }

    private function executarSemRegisto(PlanoPropina $plano): void
    {
        if ($this->referencias->existeReferenciaA($plano)) {
            throw ValidationException::withMessages([
                'eliminar' => 'Não é possível eliminar este plano de propina: já existem registos financeiros que o utilizam. Desative-o.',
            ]);
        }

        ViolacaoDeChave::comoValidacao(fn () => $plano->delete());
    }
}
