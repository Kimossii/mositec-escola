<?php

namespace Modules\Financeiro\Support;

use InvalidArgumentException;

/**
 * Registo de moedas (ISO 4217). Ponto único onde se decide símbolo e número de casas decimais:
 * nada mais no sistema assume uma moeda. Para suportar outra moeda, acrescenta-se uma linha.
 */
final class Moeda
{
    /** código => [nome, símbolo, casas decimais] */
    private const REGISTO = [
        'AED' => ['Dirham dos Emirados', 'AED', 2],
        'AOA' => ['Kwanza angolano', 'Kz', 2],
        'ARS' => ['Peso argentino', 'AR$', 2],
        'AUD' => ['Dólar australiano', 'A$', 2],
        'BHD' => ['Dinar do Bahrein', 'BD', 3],
        'BRL' => ['Real brasileiro', 'R$', 2],
        'BWP' => ['Pula do Botsuana', 'P', 2],
        'CAD' => ['Dólar canadiano', 'CA$', 2],
        'CHF' => ['Franco suíço', 'CHF', 2],
        'CLP' => ['Peso chileno', 'CLP', 0],
        'CNY' => ['Yuan chinês', 'CN¥', 2],
        'CVE' => ['Escudo cabo-verdiano', 'Esc.', 2],
        'EGP' => ['Libra egípcia', 'E£', 2],
        'EUR' => ['Euro', '€', 2],
        'GBP' => ['Libra esterlina', '£', 2],
        'GHS' => ['Cedi ganês', 'GH₵', 2],
        'INR' => ['Rupia indiana', '₹', 2],
        'JPY' => ['Iene japonês', '¥', 0],
        'KES' => ['Xelim queniano', 'KSh', 2],
        'KWD' => ['Dinar do Kuwait', 'KD', 3],
        'MAD' => ['Dirham marroquino', 'DH', 2],
        'MXN' => ['Peso mexicano', 'MX$', 2],
        'MZN' => ['Metical moçambicano', 'MT', 2],
        'NAD' => ['Dólar namibiano', 'N$', 2],
        'NGN' => ['Naira nigeriana', '₦', 2],
        'OMR' => ['Rial de Omã', 'OMR', 3],
        'RUB' => ['Rublo russo', '₽', 2],
        'RWF' => ['Franco ruandês', 'FRw', 0],
        'SAR' => ['Rial saudita', 'SAR', 2],
        'STN' => ['Dobra de São Tomé e Príncipe', 'Db', 2],
        'TND' => ['Dinar tunisino', 'DT', 3],
        'TRY' => ['Lira turca', '₺', 2],
        'TZS' => ['Xelim tanzaniano', 'TSh', 2],
        'UGX' => ['Xelim ugandês', 'USh', 0],
        'USD' => ['Dólar americano', 'US$', 2],
        'XAF' => ['Franco CFA da África Central', 'F CFA', 0],
        'XOF' => ['Franco CFA da África Ocidental', 'F CFA', 0],
        'ZAR' => ['Rand sul-africano', 'R', 2],
    ];

    private function __construct(
        public readonly string $codigo,
        public readonly string $nome,
        public readonly string $simbolo,
        public readonly int $decimais,
    ) {
    }

    public static function de(string $codigo): self
    {
        $codigo = strtoupper(trim($codigo));

        if (! isset(self::REGISTO[$codigo])) {
            throw new InvalidArgumentException("Moeda desconhecida: {$codigo}.");
        }

        [$nome, $simbolo, $decimais] = self::REGISTO[$codigo];

        return new self($codigo, $nome, $simbolo, $decimais);
    }

    public static function existe(string $codigo): bool
    {
        return isset(self::REGISTO[strtoupper(trim($codigo))]);
    }

    /**
     * @return list<self>
     */
    public static function todas(): array
    {
        return array_map(fn (string $codigo) => self::de($codigo), array_keys(self::REGISTO));
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function opcoes(): array
    {
        return array_map(
            fn (self $moeda) => ['value' => $moeda->codigo, 'label' => "{$moeda->codigo} — {$moeda->nome} ({$moeda->simbolo})"],
            self::todas(),
        );
    }

    /**
     * Unidades menores por unidade da moeda: 10 ** decimais.
     */
    public function fator(): int
    {
        return 10 ** $this->decimais;
    }

    /**
     * @return array{codigo: string, nome: string, simbolo: string, decimais: int}
     */
    public function paraFrontend(): array
    {
        return ['codigo' => $this->codigo, 'nome' => $this->nome, 'simbolo' => $this->simbolo, 'decimais' => $this->decimais];
    }
}
