<?php

namespace Modules\Avaliacao\Enums;

/**
 * As duas pautas finais de uma turma. A 1.ª fase fecha a época normal (trimestres e exame próprio): quem reprova
 * reprova, e quem tem direito vai ao recurso. A 2.ª fase só existe nas classes com recurso e só tem os alunos que lá foram.
 */
enum FasePauta: int
{
    case EPOCA_NORMAL = 1;
    case RECURSO = 2;

    public function label(): string
    {
        return match ($this) {
            self::EPOCA_NORMAL => '1.ª fase',
            self::RECURSO => 'Recurso',
        };
    }
}
