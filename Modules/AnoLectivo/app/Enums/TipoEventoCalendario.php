<?php

namespace Modules\AnoLectivo\Enums;

enum TipoEventoCalendario: int
{
    case AULA = 0;
    case AVALIACAO = 1;
    case REUNIAO = 2;
    case FERIAS = 3;
    case FERIADO = 4;
    case ACTIVIDADE = 5;
    case EVENTO = 6;
    case OUTRO = 7;

    public function label(): string
    {
        return match ($this) {
            self::AULA => 'Aula',
            self::AVALIACAO => 'Avaliação',
            self::REUNIAO => 'Reunião',
            self::FERIAS => 'Férias',
            self::FERIADO => 'Feriado',
            self::ACTIVIDADE => 'Actividade',
            self::EVENTO => 'Evento',
            self::OUTRO => 'Outro',
        };
    }
}
