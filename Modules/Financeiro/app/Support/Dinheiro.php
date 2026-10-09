<?php

namespace Modules\Financeiro\Support;

use InvalidArgumentException;

/**
 * Valor monetário em cêntimos de Kwanza (1 Kz = 100). Nunca usa float.
 * Regra de arredondamento única do sistema: percentagens arredondam para baixo
 * e, na divisão por N partes, a última absorve o resto (a soma é sempre exacta).
 */
final class Dinheiro
{
    private function __construct(private readonly int $centimos)
    {
    }

    public static function deCentimos(int $centimos): self
    {
        if ($centimos < 0) {
            throw new InvalidArgumentException('O valor monetário não pode ser negativo.');
        }

        return new self($centimos);
    }

    /**
     * Converte kwanzas (inteiro ou texto com até 2 casas decimais, "." ou ",") em cêntimos,
     * sem passar por float. Sem separador de milhares.
     */
    public static function deKwanzas(int|string $kwanzas): self
    {
        if (is_int($kwanzas)) {
            return self::deCentimos($kwanzas * 100);
        }

        if (! preg_match('/^(\d{1,12})(?:[.,](\d{1,2}))?$/D', $kwanzas, $partes)) {
            throw new InvalidArgumentException('Valor em kwanzas inválido: use dígitos com até duas casas decimais.');
        }

        $fraccao = isset($partes[2]) ? (int) str_pad($partes[2], 2, '0') : 0;

        return self::deCentimos(((int) $partes[1]) * 100 + $fraccao);
    }

    public function centimos(): int
    {
        return $this->centimos;
    }

    public function somar(self $outro): self
    {
        return self::deCentimos($this->centimos + $outro->centimos);
    }

    public function subtrair(self $outro): self
    {
        return self::deCentimos($this->centimos - $outro->centimos);
    }

    public function percentagem(int $percentagem): self
    {
        if ($percentagem < 0 || $percentagem > 100) {
            throw new InvalidArgumentException('A percentagem tem de estar entre 0 e 100.');
        }

        return new self(intdiv($this->centimos * $percentagem, 100));
    }

    /**
     * @return list<self>
     */
    public function dividir(int $partes): array
    {
        if ($partes < 1) {
            throw new InvalidArgumentException('É preciso dividir por pelo menos uma parte.');
        }

        $base = intdiv($this->centimos, $partes);
        $resultado = array_fill(0, $partes - 1, new self($base));
        $resultado[] = new self($this->centimos - $base * ($partes - 1));

        return $resultado;
    }

    public function formatar(): string
    {
        $inteira = intdiv($this->centimos, 100);
        $fraccao = str_pad((string) ($this->centimos % 100), 2, '0', STR_PAD_LEFT);

        return number_format($inteira, 0, ',', '.') . ',' . $fraccao . ' Kz';
    }
}
