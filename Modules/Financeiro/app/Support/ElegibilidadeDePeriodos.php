<?php

namespace Modules\Financeiro\Support;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Que períodos de cobrança uma matrícula deve (spec §7.2): os que acabam no mês da matrícula ou depois
 * (`periodo_fim >= 1.º dia do mês de data_matricula`). Os anteriores só se geram manualmente, com
 * `propina.ajustar` e motivo (Q9), como RETROACTIVA. Matrícula a meio de um período paga o período
 * inteiro (Q4). Classe própria e injectável (L19): o trancamento de matrícula vai estendê-la com
 * intervalos de actividade sem reescrever o gerador.
 */
class ElegibilidadeDePeriodos
{
    public function inicio(CarbonInterface $dataMatricula): CarbonImmutable
    {
        return CarbonImmutable::createFromFormat('!Y-m-d', sprintf('%04d-%02d-01', $dataMatricula->year, $dataMatricula->month));
    }

    /**
     * @param  string  $fimDoPeriodo  Y-m-d
     */
    public function anteriorAMatricula(CarbonInterface $dataMatricula, string $fimDoPeriodo): bool
    {
        return $fimDoPeriodo < $this->inicio($dataMatricula)->toDateString();
    }
}
