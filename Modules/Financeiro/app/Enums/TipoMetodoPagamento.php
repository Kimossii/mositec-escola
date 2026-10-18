<?php

namespace Modules\Financeiro\Enums;

enum TipoMetodoPagamento: int
{
    case NUMERARIO = 1;
    case TRANSFERENCIA_BANCARIA = 2;
    case TPA = 3;
    case MULTICAIXA = 4;
    case OUTRO = 5;
    case TRANSFERENCIA_EXPRESS = 6;
    case CARTEIRA_DIGITAL = 7;

    public function label(): string
    {
        return match ($this) {
            self::NUMERARIO => 'Numerário',
            self::TRANSFERENCIA_BANCARIA => 'Transferência Bancária',
            self::TPA => 'TPA',
            self::MULTICAIXA => 'Multicaixa',
            self::OUTRO => 'Outro',
            self::TRANSFERENCIA_EXPRESS => 'Transferência Express',
            self::CARTEIRA_DIGITAL => 'Carteira digital',
        };
    }

    /** Por omissão, os métodos electrónicos/remotos exigem comprovativo; os presenciais ou manuais não. */
    public function exigeComprovativoPorOmissao(): bool
    {
        return in_array($this, [
            self::TRANSFERENCIA_BANCARIA,
            self::MULTICAIXA,
            self::TRANSFERENCIA_EXPRESS,
            self::CARTEIRA_DIGITAL,
        ], true);
    }

    /**
     * @return list<array{value: int, label: string, exige_comprovativo: bool}>
     */
    public static function opcoes(): array
    {
        return array_map(
            fn (self $tipo) => ['value' => $tipo->value, 'label' => $tipo->label(), 'exige_comprovativo' => $tipo->exigeComprovativoPorOmissao()],
            self::cases(),
        );
    }
}
