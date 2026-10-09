<?php

namespace Modules\Financeiro\Enums;

enum TipoMetodoPagamento: int
{
    case NUMERARIO = 1;
    case TRANSFERENCIA_BANCARIA = 2;
    case TPA = 3;
    case MULTICAIXA = 4;
    case OUTRO = 5;

    public function label(): string
    {
        return match ($this) {
            self::NUMERARIO => 'Numerário',
            self::TRANSFERENCIA_BANCARIA => 'Transferência Bancária',
            self::TPA => 'TPA',
            self::MULTICAIXA => 'Multicaixa',
            self::OUTRO => 'Outro',
        };
    }

    /**
     * @return list<array{value: int, label: string}>
     */
    public static function opcoes(): array
    {
        return array_map(fn (self $tipo) => ['value' => $tipo->value, 'label' => $tipo->label()], self::cases());
    }
}
