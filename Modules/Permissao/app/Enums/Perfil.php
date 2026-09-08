<?php

namespace Modules\Permissao\Enums;

enum Perfil: int
{
    case ADMIN_ESCOLA = 0;
    case FUNCIONARIO = 1;
    case PROFESSOR = 2;
    case ALUNO = 3;
    case ENCARREGADO = 4;

    public function label(): string
    {
        return match ($this) {
            self::ADMIN_ESCOLA => 'Admin escola',
            self::FUNCIONARIO => 'Funcionário',
            self::PROFESSOR => 'Professor',
            self::ALUNO => 'Aluno',
            self::ENCARREGADO => 'Encarregado',
        };
    }

    public static function fromSlug(string $slug): self
    {
        return match ($slug) {
            'admin_escola' => self::ADMIN_ESCOLA,
            'funcionario' => self::FUNCIONARIO,
            'professor' => self::PROFESSOR,
            'aluno' => self::ALUNO,
            'encarregado' => self::ENCARREGADO,
        };
    }

    public function slug(): string
    {
        return match ($this) {
            self::ADMIN_ESCOLA => 'admin_escola',
            self::FUNCIONARIO => 'funcionario',
            self::PROFESSOR => 'professor',
            self::ALUNO => 'aluno',
            self::ENCARREGADO => 'encarregado',
        };
    }
}
