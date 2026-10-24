<?php

namespace Modules\Financeiro\Enums;

/**
 * Estados de um pagamento (spec Pagamentos §2.2): só Confirmado e Anulado. Regista-se já Confirmado;
 * Anulado é terminal.
 */
enum EstadoPagamento: int
{
    case CONFIRMADO = 1;
    case ANULADO = 2;

    public function label(): string
    {
        return match ($this) {
            self::CONFIRMADO => 'Confirmado',
            self::ANULADO => 'Anulado',
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
