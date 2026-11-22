<?php

namespace Modules\Horario\Enums;

use Carbon\CarbonInterface;

/** Dia da semana lectivo (ISO: segunda = 1). O domingo não existe na grelha. */
enum DiaSemana: int
{
    case SEGUNDA = 1;
    case TERCA = 2;
    case QUARTA = 3;
    case QUINTA = 4;
    case SEXTA = 5;
    case SABADO = 6;

    public function label(): string
    {
        return match ($this) {
            self::SEGUNDA => 'Segunda-feira',
            self::TERCA => 'Terça-feira',
            self::QUARTA => 'Quarta-feira',
            self::QUINTA => 'Quinta-feira',
            self::SEXTA => 'Sexta-feira',
            self::SABADO => 'Sábado',
        };
    }

    public static function fromData(CarbonInterface $data): ?self
    {
        return self::tryFrom($data->dayOfWeekIso);
    }
}
