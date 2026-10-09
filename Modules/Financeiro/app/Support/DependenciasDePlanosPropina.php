<?php

namespace Modules\Financeiro\Support;

use Modules\AnoLectivo\Contracts\DependenciasDoAnoLectivo;
use Modules\AnoLectivo\Models\AnoLectivo;
use Modules\Financeiro\Models\PlanoPropina;

/**
 * Os planos de propina (de qualquer estado) ancoram as competências no mês de data_inicio do
 * ano lectivo, e a FK não protege a eliminação (soft delete): ambos são bloqueados aqui.
 */
class DependenciasDePlanosPropina implements DependenciasDoAnoLectivo
{
    public function bloqueiaAlteracaoDeInicio(AnoLectivo $anoLectivo): ?string
    {
        return $this->temPlanos($anoLectivo)
            ? 'Este Ano Lectivo tem planos de propina: não é possível mudar o mês ou o ano da data de início, porque as competências dos planos dependem dele. Pode ajustar apenas o dia.'
            : null;
    }

    public function bloqueiaEliminacao(AnoLectivo $anoLectivo): ?string
    {
        return $this->temPlanos($anoLectivo)
            ? 'Este Ano Lectivo tem planos de propina associados e não pode ser eliminado.'
            : null;
    }

    private function temPlanos(AnoLectivo $anoLectivo): bool
    {
        return PlanoPropina::query()->where('ano_lectivo_id', $anoLectivo->id)->exists();
    }
}
