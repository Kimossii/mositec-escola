<?php

namespace Modules\Matricula\Enums;

enum EstadoInscricaoDisciplinaEnum: int
{
    case INSCRITA = 1;
    case CONCLUIDA = 2;
    case REPROVADA = 3;
    case DESISTIDA = 4;

    public function label(): string
    {
        return match ($this) {
            self::INSCRITA => 'Inscrita',
            self::CONCLUIDA => 'Concluída',
            self::REPROVADA => 'Reprovada',
            self::DESISTIDA => 'Desistida',
        };
    }

    public function podeTransitarPara(self $estado): bool
    {
        return match ($this) {
            self::INSCRITA => in_array($estado, [
                self::CONCLUIDA,
                self::REPROVADA,
                self::DESISTIDA,
            ], true),

            self::CONCLUIDA,
            self::REPROVADA,
            self::DESISTIDA => false,
        };
    }

    public function eTerminal(): bool
    {
        return match ($this) {
            self::CONCLUIDA, self::REPROVADA, self::DESISTIDA => true,
            self::INSCRITA => false,
        };
    }
}
