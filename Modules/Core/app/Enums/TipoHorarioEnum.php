<?php

namespace Modules\Core\Enums;

enum TipoHorarioEnum: int
{
    case PERIODO = 1; //representa uma faixa temporal mais abrangente.
    case TEMPO = 2; //representa uma unidade temporal menor, normalmente utilizada para uma aula.

    public function label(): string
    {
        return match ($this) {
            self::PERIODO => 'Período',
            self::TEMPO => 'Tempo',
        };
    }
}
