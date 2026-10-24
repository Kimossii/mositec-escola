<?php

namespace Modules\Financeiro\Support;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Meses tocados por uma pausa de cobrança: função pura, sem base de dados (estilo CalendarioDePlano).
 * Um mês é coberto por inteiro quando a pausa começa até ao seu dia 1 e termina a partir do seu último dia;
 * caso contrário é coberto só parcialmente. A isenção dos meses inteiros é automática; a dos parciais fica
 * à escolha do utilizador.
 */
final class MesesDePausa
{
    /**
     * @return list<array{ano: int, mes: int, completo: bool}>
     */
    public static function tocados(CarbonInterface $dataInicio, CarbonInterface $dataFim): array
    {
        $inicio = CarbonImmutable::parse($dataInicio->toDateString());
        $fim = $dataFim->toDateString();

        $cursor = CarbonImmutable::createFromDate($inicio->year, $inicio->month, 1)->startOfDay();
        $tocados = [];

        while ($cursor->toDateString() <= $fim) {
            $primeiro = $cursor->toDateString();
            $ultimo = $cursor->endOfMonth()->toDateString();

            $tocados[] = [
                'ano' => $cursor->year,
                'mes' => $cursor->month,
                'completo' => $inicio->toDateString() <= $primeiro && $fim >= $ultimo,
            ];

            $cursor = $cursor->addMonth()->startOfMonth();
        }

        return $tocados;
    }
}
