<?php

namespace Modules\Financeiro\Enums;

/** Quando um item obrigatório do catálogo se cobra ao aluno. */
enum MomentoObrigatorio: int
{
    case AO_MATRICULAR = 1; // a cada matrícula
    case UMA_VEZ_POR_ANO = 2; // uma vez por ano lectivo
    case UMA_SO_VEZ = 3; // uma só vez na vida do aluno (ex.: cartão de estudante)

    public function label(): string
    {
        return match ($this) {
            self::AO_MATRICULAR => 'Ao matricular',
            self::UMA_VEZ_POR_ANO => 'Uma vez por ano lectivo',
            self::UMA_SO_VEZ => 'Uma só vez',
        };
    }

    /** @return list<array{value: int, label: string}> */
    public static function opcoes(): array
    {
        return array_map(fn (self $m) => ['value' => $m->value, 'label' => $m->label()], self::cases());
    }
}
