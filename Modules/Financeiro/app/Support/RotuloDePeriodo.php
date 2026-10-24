<?php

namespace Modules\Financeiro\Support;

/**
 * Rótulos curtos de competências e períodos de cobrança para mensagens e listas ("Set/2026",
 * "Set–Nov/2026", "Dez/2026–Fev/2027"). Recebe datas Y-m-d já validadas (vindas do calendário do plano
 * ou da própria propina).
 */
final class RotuloDePeriodo
{
    private const MESES = ['Jan', 'Fev', 'Mar', 'Abr', 'Mai', 'Jun', 'Jul', 'Ago', 'Set', 'Out', 'Nov', 'Dez'];

    public static function competencia(int $ano, int $mes): string
    {
        return self::MESES[$mes - 1] . '/' . $ano;
    }

    public static function periodo(string $inicio, string $fim): string
    {
        $anoInicio = (int) substr($inicio, 0, 4);
        $mesInicio = (int) substr($inicio, 5, 2);
        $anoFim = (int) substr($fim, 0, 4);
        $mesFim = (int) substr($fim, 5, 2);

        if ($anoInicio === $anoFim && $mesInicio === $mesFim) {
            return self::competencia($anoInicio, $mesInicio);
        }

        if ($anoInicio === $anoFim) {
            return self::MESES[$mesInicio - 1] . '–' . self::MESES[$mesFim - 1] . '/' . $anoFim;
        }

        return self::competencia($anoInicio, $mesInicio) . '–' . self::competencia($anoFim, $mesFim);
    }
}
