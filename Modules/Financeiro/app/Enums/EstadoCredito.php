<?php

namespace Modules\Financeiro\Enums;

/**
 * Estados de um crédito do aluno (spec Pagamentos §3). DISPONIVEL/ESGOTADO derivam de valor_utilizado
 * (RecalcularCredito); ANULADO acompanha a anulação do pagamento de origem e é terminal.
 */
enum EstadoCredito: int
{
    case DISPONIVEL = 1;
    case ESGOTADO = 2;
    case ANULADO = 3;

    public function label(): string
    {
        return match ($this) {
            self::DISPONIVEL => 'Disponível',
            self::ESGOTADO => 'Esgotado',
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
