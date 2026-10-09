<?php

namespace Modules\Financeiro\Support;

use InvalidArgumentException;

/**
 * Regras de configuração das multas por atraso. Ponto único do limite de escalões e da
 * conversão de percentagens: o valor de um escalão PERCENTAGEM guarda-se em pontos-base
 * (100 = 1%, 10000 = 100%); o texto que o utilizador escreve é a percentagem ("2,5").
 */
final class Multa
{
    public const MAX_ESCALOES = 3;

    public const MAX_DIAS_ATRASO = 3650;

    public const PONTOS_BASE_MAXIMOS = 10_000;

    /**
     * "2,5" ou "2.5" (até 2 casas) => 250 pontos-base. Rejeita zero e mais de 100%.
     */
    public static function percentagemParaPontosBase(int|float|string $texto): int
    {
        $texto = is_string($texto) ? $texto : (string) $texto;

        if (! preg_match('/^(\d{1,3})(?:[.,](\d{1,2}))?$/D', $texto, $partes)) {
            throw new InvalidArgumentException('Percentagem inválida.');
        }

        $pontos = ((int) $partes[1]) * 100 + (isset($partes[2]) ? (int) str_pad($partes[2], 2, '0') : 0);

        if ($pontos < 1 || $pontos > self::PONTOS_BASE_MAXIMOS) {
            throw new InvalidArgumentException('A percentagem tem de ser superior a 0% e no máximo 100%.');
        }

        return $pontos;
    }

    /**
     * Inverso de percentagemParaPontosBase para preencher o input: 250 => "2,5", 500 => "5".
     */
    public static function pontosBaseParaPercentagem(int $pontos): string
    {
        $inteira = intdiv($pontos, 100);
        $fraccao = $pontos % 100;

        if ($fraccao === 0) {
            return (string) $inteira;
        }

        return $inteira . ',' . rtrim(str_pad((string) $fraccao, 2, '0', STR_PAD_LEFT), '0');
    }
}
