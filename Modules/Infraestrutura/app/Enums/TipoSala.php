<?php

namespace Modules\Infraestrutura\Enums;

enum TipoSala: int
{
    case SALA_AULA = 0;
    case LABORATORIO = 1;
    case BIBLIOTECA = 2;
    case AUDITORIO = 3;
    case GINASIO = 4;
    case SALA_PROFESSORES = 5;
    case GABINETE_ADMINISTRATIVO = 6;
    case OUTRO = 7;

    public function label(): string
    {
        return match ($this) {
            self::SALA_AULA => 'Sala de Aula',
            self::LABORATORIO => 'Laboratório',
            self::BIBLIOTECA => 'Biblioteca',
            self::AUDITORIO => 'Auditório',
            self::GINASIO => 'Ginásio',
            self::SALA_PROFESSORES => 'Sala de Professores',
            self::GABINETE_ADMINISTRATIVO => 'Gabinete Administrativo',
            self::OUTRO => 'Outro',
        };
    }
}
