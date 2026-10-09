<?php

namespace Modules\Financeiro\Support;

use InvalidArgumentException;

/**
 * Taxa de câmbio de referência no sentido "1 USD = X unidades da moeda da escola", guardada como
 * inteiro escalado a 6 casas (taxa × 1.000.000). Nunca usa float.
 */
final class TaxaCambio
{
    public const ESCALA = 1_000_000;

    /** Maior parte inteira aceite: 9 dígitos, o mesmo limite do texto. */
    private const MAXIMO_INTEIRO = 999_999_999;

    private function __construct(private readonly int $micros)
    {
    }

    public static function deMicros(int $micros): self
    {
        if ($micros <= 0) {
            throw new InvalidArgumentException('A taxa de câmbio tem de ser maior que zero.');
        }

        return new self($micros);
    }

    public static function deDecimal(int|string $valor): self
    {
        if (is_int($valor)) {
            if ($valor > self::MAXIMO_INTEIRO) {
                throw new InvalidArgumentException('Taxa de câmbio inválida.');
            }

            return self::deMicros($valor * self::ESCALA);
        }

        if (! preg_match('/^(\d{1,9})(?:[.,](\d{1,6}))?$/D', $valor, $partes)) {
            throw new InvalidArgumentException('Taxa de câmbio inválida.');
        }

        $fraccao = isset($partes[2]) ? (int) str_pad($partes[2], 6, '0') : 0;

        return self::deMicros(((int) $partes[1]) * self::ESCALA + $fraccao);
    }

    public static function um(): self
    {
        return new self(self::ESCALA);
    }

    public function micros(): int
    {
        return $this->micros;
    }

    /**
     * "910,00", "910,50", "910,123456": no mínimo 2 casas, sem zeros a mais; milhares com ".".
     */
    public function formatar(): string
    {
        return number_format(intdiv($this->micros, self::ESCALA), 0, ',', '.') . ',' . $this->fraccao();
    }

    /**
     * Para preencher um <input>: ponto decimal e sem separador de milhares.
     */
    public function paraInput(): string
    {
        return intdiv($this->micros, self::ESCALA) . '.' . $this->fraccao();
    }

    private function fraccao(): string
    {
        $fraccao = rtrim(str_pad((string) ($this->micros % self::ESCALA), 6, '0', STR_PAD_LEFT), '0');

        return str_pad($fraccao, 2, '0');
    }
}
