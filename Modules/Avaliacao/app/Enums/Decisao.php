<?php

namespace Modules\Avaliacao\Enums;

/** Decisão final do aluno. Transita/Não transita (1.ª–5.ª, 7.ª–8.ª); Apto/Não apto (6.ª e 9.ª, com exame). */
enum Decisao: int
{
    case TRANSITA = 0;
    case NAO_TRANSITA = 1;
    case APTO = 2;
    case NAO_APTO = 3;
    /** Resultado da 1.ª fase de quem vai ao exame de recurso: a decisão final sai na pauta do recurso. */
    case RECURSO = 4;

    public function label(): string
    {
        return match ($this) {
            self::TRANSITA => 'Transita',
            self::NAO_TRANSITA => 'Não transita',
            self::APTO => 'Apto',
            self::NAO_APTO => 'Não apto',
            self::RECURSO => 'Recurso',
        };
    }
}
