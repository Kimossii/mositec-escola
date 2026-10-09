<?php

namespace Modules\Financeiro\Support;

use Modules\Financeiro\Models\PlanoPropina;

/**
 * Resultado de escolher o plano de propina de uma turma: um plano, nenhum, ou conflito
 * (dois ou mais planos com a mesma precedência). Nunca se escolhe um vencedor arbitrário.
 */
final class ResultadoResolucaoPlano
{
    /**
     * @param  list<PlanoPropina>  $candidatos
     */
    private function __construct(
        public readonly ?PlanoPropina $plano,
        public readonly bool $conflito,
        public readonly array $candidatos,
    ) {
    }

    public static function aplicavel(PlanoPropina $plano): self
    {
        return new self($plano, false, [$plano]);
    }

    public static function semPlano(): self
    {
        return new self(null, false, []);
    }

    /**
     * @param  list<PlanoPropina>  $candidatos
     */
    public static function conflito(array $candidatos): self
    {
        return new self(null, true, $candidatos);
    }

    public function temPlano(): bool
    {
        return $this->plano !== null && ! $this->conflito;
    }
}
