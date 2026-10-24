<?php

namespace Modules\Financeiro\Enums;

enum EstadoUtilizacaoCredito: int
{
    case ACTIVA = 1;
    case ANULADA = 2;

    public function label(): string
    {
        return match ($this) {
            self::ACTIVA => 'Activa',
            self::ANULADA => 'Anulada',
        };
    }

    /**
     * @return list<array{value: int, label: string}>
     */
    public static function opcoes(): array
    {
        return array_map(fn (self $estado) => ['value' => $estado->value, 'label' => $estado->label()], self::cases());
    }
}
