<?php

namespace Modules\Financeiro\Enums;

enum TipoMulta: int
{
    case PERCENTAGEM = 0;
    case VALOR_FIXO = 1;

    public function label(): string
    {
        return match ($this) {
            self::PERCENTAGEM => 'Percentagem',
            self::VALOR_FIXO => 'Valor fixo',
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
