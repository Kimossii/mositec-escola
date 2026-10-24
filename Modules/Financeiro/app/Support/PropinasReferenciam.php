<?php

namespace Modules\Financeiro\Support;

use Illuminate\Database\Eloquent\Model;
use Modules\Financeiro\Contracts\ReferenciaFinanceira;
use Modules\Financeiro\Models\ConfiguracaoMonetaria;
use Modules\Financeiro\Models\PlanoPropina;
use Modules\Financeiro\Models\Propina;

/**
 * Propinas são registos operacionais: um plano com propinas (em qualquer estado) não se elimina, e a
 * moeda da escola não muda enquanto existir qualquer propina (o contrato exige-o para a
 * ConfiguracaoMonetaria). Matrícula, turma e ano lectivo não são configuração financeira: ficam
 * protegidos pela FK restrict, pelo módulo Matricula (só Pendentes se eliminam) e pelos planos.
 */
class PropinasReferenciam implements ReferenciaFinanceira
{
    public function existeReferenciaA(Model $configuracao): bool
    {
        return match (true) {
            $configuracao instanceof PlanoPropina => Propina::query()->where('plano_propina_id', $configuracao->getKey())->exists(),
            $configuracao instanceof ConfiguracaoMonetaria => Propina::query()->exists(),
            default => false,
        };
    }
}
