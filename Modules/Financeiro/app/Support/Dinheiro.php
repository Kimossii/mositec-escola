<?php

namespace Modules\Financeiro\Support;

use InvalidArgumentException;

/**
 * Valor monetário em UNIDADES MENORES da moeda (cêntimos, ou a própria unidade em moedas sem
 * casas decimais). Não conhece a moeda: parsing e formatação recebem-na. Nunca usa float.
 * Regra de arredondamento única do sistema: percentagens arredondam para baixo e, na divisão
 * por N partes, a última absorve o resto (a soma é sempre exacta).
 */
final class Dinheiro
{
    /** Maior parte inteira aceite: 12 dígitos, o mesmo limite do texto. */
    private const MAXIMO_INTEIRO = 999_999_999_999;

    private function __construct(private readonly int $unidadesMenores)
    {
    }

    public static function deUnidadesMenores(int $unidadesMenores): self
    {
        if ($unidadesMenores < 0) {
            throw new InvalidArgumentException('O valor monetário não pode ser negativo.');
        }

        return new self($unidadesMenores);
    }

    /**
     * Converte um valor decimal da moeda (inteiro, ou texto com "." ou "," e até N casas, N = casas
     * da moeda; sem separador de milhares) em unidades menores, sem passar por float.
     */
    public static function deDecimal(int|string $valor, Moeda $moeda): self
    {
        if (is_int($valor)) {
            if ($valor > self::MAXIMO_INTEIRO) {
                throw new InvalidArgumentException("Valor monetário inválido para {$moeda->codigo}.");
            }

            return self::deUnidadesMenores($valor * $moeda->fator());
        }

        $padrao = $moeda->decimais === 0
            ? '/^(\d{1,12})$/D'
            : '/^(\d{1,12})(?:[.,](\d{1,' . $moeda->decimais . '}))?$/D';

        if (! preg_match($padrao, $valor, $partes)) {
            throw new InvalidArgumentException("Valor monetário inválido para {$moeda->codigo}.");
        }

        $fraccao = isset($partes[2]) ? (int) str_pad($partes[2], $moeda->decimais, '0') : 0;

        return self::deUnidadesMenores(((int) $partes[1]) * $moeda->fator() + $fraccao);
    }

    public function unidadesMenores(): int
    {
        return $this->unidadesMenores;
    }

    public function somar(self $outro): self
    {
        return self::deUnidadesMenores($this->unidadesMenores + $outro->unidadesMenores);
    }

    public function subtrair(self $outro): self
    {
        return self::deUnidadesMenores($this->unidadesMenores - $outro->unidadesMenores);
    }

    public function percentagem(int $percentagem): self
    {
        if ($percentagem < 0 || $percentagem > 100) {
            throw new InvalidArgumentException('A percentagem tem de estar entre 0 e 100.');
        }

        return new self(intdiv($this->unidadesMenores * $percentagem, 100));
    }

    /**
     * @return list<self>
     */
    public function dividir(int $partes): array
    {
        if ($partes < 1) {
            throw new InvalidArgumentException('É preciso dividir por pelo menos uma parte.');
        }

        $base = intdiv($this->unidadesMenores, $partes);
        $resultado = array_fill(0, $partes - 1, new self($base));
        $resultado[] = new self($this->unidadesMenores - $base * ($partes - 1));

        return $resultado;
    }

    /**
     * Texto canónico para <input> e exportações: ponto decimal, sem milhares ("25000.50", "25000").
     * É o inverso exacto de deDecimal().
     */
    public function paraDecimal(Moeda $moeda): string
    {
        $fator = $moeda->fator();
        $inteira = intdiv($this->unidadesMenores, $fator);

        if ($moeda->decimais === 0) {
            return (string) $inteira;
        }

        return $inteira . '.' . str_pad((string) ($this->unidadesMenores % $fator), $moeda->decimais, '0', STR_PAD_LEFT);
    }

    /**
     * Milhares com ".", decimais com "," e o símbolo da moeda depois de um espaço.
     */
    public function formatar(Moeda $moeda): string
    {
        $fator = $moeda->fator();
        $milhares = number_format(intdiv($this->unidadesMenores, $fator), 0, ',', '.');

        if ($moeda->decimais === 0) {
            return "{$milhares} {$moeda->simbolo}";
        }

        $fraccao = str_pad((string) ($this->unidadesMenores % $fator), $moeda->decimais, '0', STR_PAD_LEFT);

        return "{$milhares},{$fraccao} {$moeda->simbolo}";
    }
}
