<?php

namespace Modules\Estabelecimento\Enums;

enum TipoEstabelecimentoEnum: int
{
    case PUBLICO = 1;
    case PRIVADO = 2;
    case COOPERATIVO = 3;

    public function label(): string
    {
        return match ($this) {
            self::PUBLICO => 'Público',
            self::PRIVADO => 'Privado',
            self::COOPERATIVO => 'Cooperativo',
        };
    }
}
