<?php

namespace Modules\Financeiro\Support;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use InvalidArgumentException;

/**
 * Calendário de cobrança de um plano de propina: funções puras, sem base de dados.
 * O período (mes_inicio -> mes_fim) pode atravessar o ano civil (Set -> Jun).
 */
final class CalendarioDePlano
{
    public static function meses(int $mesInicio, int $mesFim): int
    {
        self::validarMes($mesInicio);
        self::validarMes($mesFim);

        return (($mesFim - $mesInicio + 12) % 12) + 1;
    }

    /**
     * Competências (ano, mês) do período. A primeira é a primeira ocorrência de $mesInicio
     * igual ou posterior ao mês de início do ano lectivo.
     *
     * @return list<array{ano: int, mes: int}>
     */
    public static function competencias(int $mesInicio, int $mesFim, CarbonInterface $inicioAnoLectivo): array
    {
        $total = self::meses($mesInicio, $mesFim);
        $anoDaPrimeira = $mesInicio >= $inicioAnoLectivo->month ? $inicioAnoLectivo->year : $inicioAnoLectivo->year + 1;

        $competencias = [];
        for ($i = 0; $i < $total; $i++) {
            $indice = ($mesInicio - 1) + $i;
            $competencias[] = ['ano' => $anoDaPrimeira + intdiv($indice, 12), 'mes' => ($indice % 12) + 1];
        }

        return $competencias;
    }

    /**
     * Períodos de cobrança: as competências agrupadas de $intervaloMeses em $intervaloMeses.
     * O último período pode ser mais curto.
     *
     * @return list<array{ordem: int, meses: int, inicio: string, fim: string}>
     */
    public static function periodos(int $mesInicio, int $mesFim, int $intervaloMeses, CarbonInterface $inicioAnoLectivo): array
    {
        if ($intervaloMeses < 1 || $intervaloMeses > 12) {
            throw new InvalidArgumentException('O intervalo de cobrança tem de estar entre 1 e 12 meses.');
        }

        $periodos = [];
        foreach (array_chunk(self::competencias($mesInicio, $mesFim, $inicioAnoLectivo), $intervaloMeses) as $indice => $grupo) {
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

    private static function validarMes(int $mes): void
    {
        if ($mes < 1 || $mes > 12) {
            throw new InvalidArgumentException('O mês tem de estar entre 1 e 12.');
        }
    }
}
