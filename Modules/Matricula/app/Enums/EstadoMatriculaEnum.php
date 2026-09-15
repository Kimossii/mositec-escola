<?php

namespace Modules\Matricula\Enums;

enum EstadoMatriculaEnum: int
{
    case PENDENTE = 1;
    case ACTIVA = 2;
    case CANCELADA = 3;
    case CONCLUIDA = 4;
    case TRANSFERIDA = 5;

    public function label(): string
    {
        return match ($this) {
            self::PENDENTE => 'Pendente',
            self::ACTIVA => 'Activa',
            self::CANCELADA => 'Cancelada',
            self::CONCLUIDA => 'Concluída',
            self::TRANSFERIDA => 'Transferida',
        };
    }

    public function podeTransitarPara(self $estado): bool
    {
        return match ($this) {
            self::PENDENTE => in_array($estado, [
                self::ACTIVA,
                self::CANCELADA,
            ], true),

            self::ACTIVA => in_array($estado, [
                self::CONCLUIDA,
                self::CANCELADA,
                self::TRANSFERIDA,
            ], true),

            self::CANCELADA,
            self::CONCLUIDA,
            self::TRANSFERIDA => false,
        };
    }

    public function eTerminal(): bool
    {
        return match ($this) {
            self::CANCELADA, self::CONCLUIDA, self::TRANSFERIDA => true,
            self::PENDENTE, self::ACTIVA => false,
        };
    }
}
