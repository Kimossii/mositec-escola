<?php

namespace Modules\Financeiro\Support;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use InvalidArgumentException;

/**
 * Calendário de cobrança de um plano de propina: funções puras, sem base de dados. O plano vale de uma
 * data a outra (data_inicio -> data_fim); as competências (ano, mês) derivam-se directamente das datas —
 * do mês de data_inicio ao mês de data_fim, inclusive — e o ano vem das próprias datas (nunca é deduzido
 * do ano lectivo). Cada mês tocado conta como mês inteiro (15/09 -> 20/06 = Set a Jun, 10 meses).
 */
final class CalendarioDePlano
{
    public static function meses(CarbonInterface $dataInicio, CarbonInterface $dataFim): int
    {
        return count(self::competencias($dataInicio, $dataFim));
    }

    /**
     * Competências (ano, mês) cobertas, da primeira à última, inclusive.
     *
     * @return list<array{ano: int, mes: int}>
     */
    public static function competencias(CarbonInterface $dataInicio, CarbonInterface $dataFim): array
    {
        $cursor = CarbonImmutable::createFromDate($dataInicio->year, $dataInicio->month, 1)->startOfDay();
        $fim = CarbonImmutable::createFromDate($dataFim->year, $dataFim->month, 1)->startOfDay();

        $competencias = [];

        while ($cursor->lte($fim)) {
            $competencias[] = ['ano' => $cursor->year, 'mes' => $cursor->month];
            $cursor = $cursor->addMonth();
        }

        return $competencias;
    }

    /**
     * Períodos de cobrança: as competências agrupadas de $intervaloMeses em $intervaloMeses.
     * O último período pode ser mais curto.
     *
     * @return list<array{ordem: int, meses: int, inicio: string, fim: string}>
     */
    public static function periodos(CarbonInterface $dataInicio, CarbonInterface $dataFim, int $intervaloMeses): array
    {
        if ($intervaloMeses < 1 || $intervaloMeses > 12) {
            throw new InvalidArgumentException('O intervalo de cobrança tem de estar entre 1 e 12 meses.');
        }

        $periodos = [];

        foreach (array_chunk(self::competencias($dataInicio, $dataFim), $intervaloMeses) as $indice => $grupo) {
            $primeiro = $grupo[0];
            $ultimo = $grupo[array_key_last($grupo)];

            $periodos[] = [
                'ordem' => $indice + 1,
                'meses' => count($grupo),
                'inicio' => CarbonImmutable::createFromDate($primeiro['ano'], $primeiro['mes'], 1)->startOfDay()->toDateString(),
                'fim' => CarbonImmutable::createFromDate($ultimo['ano'], $ultimo['mes'], 1)->endOfMonth()->toDateString(),
            ];
        }

        return $periodos;
    }

    /**
     * Duração, em meses, de cada período de cobrança; o último pode ser mais curto.
     *
     * @return list<int>
     */
    public static function duracoes(CarbonInterface $dataInicio, CarbonInterface $dataFim, int $intervaloMeses): array
    {
        if ($intervaloMeses < 1 || $intervaloMeses > 12) {
            throw new InvalidArgumentException('O intervalo de cobrança tem de estar entre 1 e 12 meses.');
        }

        $total = self::meses($dataInicio, $dataFim);
        $duracoes = array_fill(0, intdiv($total, $intervaloMeses), $intervaloMeses);

        if ($total % $intervaloMeses !== 0) {
            $duracoes[] = $total % $intervaloMeses;
        }

        return $duracoes;
    }

    /**
     * Duas listas de competências têm algum mês (ano + mês) em comum?
     *
     * @param  list<array{ano: int, mes: int}>  $a
     * @param  list<array{ano: int, mes: int}>  $b
     */
    public static function sobrepoem(array $a, array $b): bool
    {
        $ordinaisDeA = array_map(fn (array $c) => $c['ano'] * 12 + $c['mes'], $a);

        foreach ($b as $competencia) {
            if (in_array($competencia['ano'] * 12 + $competencia['mes'], $ordinaisDeA, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<array{ano: int, mes: int}>  $competencias
     */
    public static function contem(array $competencias, int $ano, int $mes): bool
    {
        return self::sobrepoem($competencias, [['ano' => $ano, 'mes' => $mes]]);
    }
}
