<?php

namespace Modules\AnoLectivo\Support;

use Carbon\CarbonImmutable;

/**
 * Os feriados nacionais de Angola de um ano civil: os fixos e os que dependem da Páscoa (Carnaval, Sexta-feira Santa).
 * Lista de partida, a confirmar pela escola: cada feriado fica como um evento normal do calendário e pode ser editado
 * ou eliminado. Não aplica nenhuma regra de transferência para a segunda-feira quando cai ao fim-de-semana.
 */
class FeriadosNacionaisDeAngola
{
    /** Feriados de data fixa: mês-dia → nome. */
    private const FIXOS = [
        '01-01' => 'Ano Novo',
        '02-04' => 'Dia do Início da Luta Armada de Libertação Nacional',
        '03-08' => 'Dia Internacional da Mulher',
        '04-04' => 'Dia da Paz e Reconciliação Nacional',
        '05-01' => 'Dia Internacional do Trabalhador',
        '09-17' => 'Dia do Fundador da Nação e do Herói Nacional',
        '11-02' => 'Dia de Finados',
        '11-11' => 'Dia da Independência Nacional',
        '12-25' => 'Dia de Natal',
    ];

    /** @return list<array{data: string, nome: string}> por ordem de data */
    public function doAno(int $ano): array
    {
        $feriados = [];

        foreach (self::FIXOS as $mesDia => $nome) {
            $feriados["{$ano}-{$mesDia}"] = $nome;
        }

        $pascoa = $this->pascoa($ano);
        $feriados[$pascoa->subDays(47)->toDateString()] = 'Carnaval';
        $feriados[$pascoa->subDays(2)->toDateString()] = 'Sexta-feira Santa';

        ksort($feriados);

        return array_map(fn (string $data, string $nome) => ['data' => $data, 'nome' => $nome], array_keys($feriados), $feriados);
    }

    /** Domingo de Páscoa (calendário gregoriano; algoritmo de Meeus/Jones/Butcher). */
    private function pascoa(int $ano): CarbonImmutable
    {
        $a = $ano % 19;
        $b = intdiv($ano, 100);
        $c = $ano % 100;
        $d = intdiv($b, 4);
        $e = $b % 4;
        $f = intdiv($b + 8, 25);
        $g = intdiv($b - $f + 1, 3);
        $h = (19 * $a + $b - $d - $g + 15) % 30;
        $i = intdiv($c, 4);
        $k = $c % 4;
        $l = (32 + 2 * $e + 2 * $i - $h - $k) % 7;
        $m = intdiv($a + 11 * $h + 22 * $l, 451);
        $mes = intdiv($h + $l - 7 * $m + 114, 31);
        $dia = (($h + $l - 7 * $m + 114) % 31) + 1;

        return CarbonImmutable::create($ano, $mes, $dia)->startOfDay();
    }
}
