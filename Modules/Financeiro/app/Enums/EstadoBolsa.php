<?php

namespace Modules\Financeiro\Enums;

enum EstadoBolsa: int
{
    case ACTIVA = 1;
    case TERMINADA = 2;

    public function label(): string
    {
        return match ($this) {
            self::ACTIVA => 'Activa',
            self::TERMINADA => 'Terminada',
        };
    }

    /** @return list<array{value: int, label: string}> */
    public static function opcoes(): array
    {
        return array_map(fn (self $e) => ['value' => $e->value, 'label' => $e->label()], self::cases());
    }
}
