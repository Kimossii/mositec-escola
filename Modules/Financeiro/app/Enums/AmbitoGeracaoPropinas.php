<?php

namespace Modules\Financeiro\Enums;

/**
 * Que matrículas uma geração de propinas percorre. Não é persistido. TODAS é só do comando automático;
 * a geração manual escolhe uma matrícula, uma turma, um nível ou as turmas de um plano (spec §7.5).
 */
enum AmbitoGeracaoPropinas: string
{
    case MATRICULA = 'matricula';
    case TURMA = 'turma';
    case NIVEL = 'nivel';
    case PLANO = 'plano';
    case TODAS = 'todas';

    /**
     * @return list<self>
     */
    public static function manuais(): array
    {
        return [self::MATRICULA, self::TURMA, self::NIVEL, self::PLANO];
    }
}
