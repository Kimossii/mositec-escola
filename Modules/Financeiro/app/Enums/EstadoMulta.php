<?php

namespace Modules\Financeiro\Enums;

/**
 * Estados de uma multa por atraso (spec Propinas §15.2). ACTIVA é o estado de uma multa aplicada e nunca
 * reduzida; REDUZIDA/ISENTA resultam da isenção manual; ANULADA acompanha o cancelamento/anulação da propina.
 */
enum EstadoMulta: int
{
    case ACTIVA = 1;
    case REDUZIDA = 2;
    case ISENTA = 3;
    case ANULADA = 4;

    public function label(): string
    {
        return match ($this) {
            self::ACTIVA => 'Activa',
            self::REDUZIDA => 'Reduzida',
            self::ISENTA => 'Isenta',
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
