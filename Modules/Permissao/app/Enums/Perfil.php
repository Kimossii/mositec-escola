<?php

namespace Modules\Permissao\Enums;

enum Perfil: int
{
    case ADMIN_ESCOLA = 0;
    case SECRETARIO = 1;
    case PROFESSOR = 2;
    case ALUNO = 3;
    case ENCARREGADO = 4;

    public function label(): string
    {
        return match ($this) {
            self::ADMIN_ESCOLA => 'Admin escola',
            self::SECRETARIO => 'Secretário',
            self::PROFESSOR => 'Professor',
            self::ALUNO => 'Aluno',
            self::ENCARREGADO => 'Encarregado',
        };
    }
}
