<?php

namespace Modules\Avaliacao\Enums;

/** Como se decide a progressão no Pré-Escolar e na Creche: todos transitam, ou decide-se criança a criança. */
enum Progressao: int
{
    case AUTOMATICA = 0;
    case MANUAL = 1;

    public function label(): string
    {
        return match ($this) {
            self::AUTOMATICA => 'Automática (todas as crianças transitam)',
            self::MANUAL => 'Decidida criança a criança',
        };
    }
}
