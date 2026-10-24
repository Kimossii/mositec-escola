<?php

namespace Modules\Financeiro\Enums;

enum Periodicidade: int
{
    case OUTRA = 0;
    case MENSAL = 1;
    case BIMESTRAL = 2;
    case TRIMESTRAL = 3;
    case SEMESTRAL = 6;
    case ANUAL = 12;

    public function label(): string
    {
        return match ($this) {
            self::OUTRA => 'Outra periodicidade',
            self::MENSAL => 'Mensal',
            self::BIMESTRAL => 'Bimestral',
            self::TRIMESTRAL => 'Trimestral',
            self::SEMESTRAL => 'Semestral',
            self::ANUAL => 'Anual',
        };
    }

    /**
     * Meses de cada período de cobrança; null em OUTRA (o plano indica o intervalo).
     */
    public function meses(): ?int
    {
        return $this === self::OUTRA ? null : $this->value;
    }

    /**
     * @return list<array{value: int, label: string}>
     */
    public static function opcoes(): array
    {
        $ordem = [self::MENSAL, self::BIMESTRAL, self::TRIMESTRAL, self::SEMESTRAL, self::ANUAL, self::OUTRA];

        return array_map(fn (self $p) => ['value' => $p->value, 'label' => $p->label()], $ordem);
    }
}
