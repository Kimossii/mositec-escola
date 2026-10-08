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
