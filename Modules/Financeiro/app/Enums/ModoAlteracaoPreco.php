<?php

namespace Modules\Financeiro\Enums;

/**
 * Como tratar as propinas já geradas quando o preço de um plano com propinas muda (spec Propinas §14).
 * Não há valor por omissão: o utilizador escolhe explicitamente a cada alteração.
 */
enum ModoAlteracaoPreco: int
{
    case APLICAR_GERADAS = 1;
    case MANTER_GERADAS = 2;

    public function label(): string
    {
        return match ($this) {
            self::APLICAR_GERADAS => 'Aplicar às propinas já geradas',
            self::MANTER_GERADAS => 'Manter as já geradas',
        };
    }

    /**
     * @return list<array{value: int, label: string}>
     */
    public static function opcoes(): array
    {
        return array_map(fn (self $modo) => ['value' => $modo->value, 'label' => $modo->label()], self::cases());
    }
}
