<?php

namespace Modules\Financeiro\Enums;

/**
 * Origem de uma revisão de multa (spec Propinas §15.2): AUTOMATICA (comando diário) ou MANUAL (isenção/redução).
 */
enum OrigemRevisaoMulta: int
{
    case AUTOMATICA = 0;
    case MANUAL = 1;

    public function label(): string
    {
        return match ($this) {
            self::AUTOMATICA => 'Automática',
            self::MANUAL => 'Manual',
        };
    }

    /**
     * @return list<array{value: int, label: string}>
     */
    public static function opcoes(): array
    {
        return array_map(fn (self $origem) => ['value' => $origem->value, 'label' => $origem->label()], self::cases());
    }
}
