<?php

namespace Modules\Permissao\Enums;

enum Modulo: int
{
    case USUARIO = 0;
    case AUTORIZACAO = 1;
    case ANO_LECTIVO = 2;
    case LICENCA = 3;
    case ALUNO = 4;
    case PROFESSOR = 5;
    case TURMAS = 6;
    case MATRICULA = 7;
    case DISCIPLINA = 8;
    case NOTA = 9;
    case ESTABELECIMENTO = 10;
    case HORARIO = 11;

    public function slug(): string
    {
        return match ($this) {
            self::USUARIO => 'usuario',
            self::AUTORIZACAO => 'autorizacao',
            self::ANO_LECTIVO => 'ano-lectivo',
            self::LICENCA => 'licenca',
            self::ALUNO => 'aluno',
            self::PROFESSOR => 'professor',
            self::TURMAS => 'turmas',
            self::MATRICULA => 'matricula',
            self::DISCIPLINA => 'disciplina',
            self::NOTA => 'nota',
            self::ESTABELECIMENTO => 'estabelecimento',
            self::HORARIO => 'horario',
        };
    }

    public static function fromSlug(string $slug): ?self
    {
        foreach (self::cases() as $modulo) {
            if ($modulo->slug() === $slug) {
                return $modulo;
            }
        }

        return null;
    }

    public function label(): string
    {
        return match ($this) {
            self::USUARIO => 'Utilizadores',
            self::AUTORIZACAO => 'Autorização',
            self::ANO_LECTIVO => 'Ano Lectivo',
            self::LICENCA => 'Licença',
            self::ALUNO => 'Aluno',
            self::PROFESSOR => 'Professor',
            self::TURMAS => 'Turmas',
            self::MATRICULA => 'Matrícula',
            self::DISCIPLINA => 'Disciplina',
            self::NOTA => 'Nota',
            self::ESTABELECIMENTO => 'Estabelecimento',
            self::HORARIO => 'Horário',
        };
    }
}
