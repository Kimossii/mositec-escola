<?php

namespace Modules\Aluno\Enums;

enum EstadoEnquadramentoAcademicoEnum: int
{
    case ACTIVO = 1;
    case CONCLUIDO = 2;
    case CANCELADO = 3;
    case TRANSFERIDO = 4;

    public function label(): string
    {
        return match ($this) {
            self::ACTIVO => 'Activo',
            self::CONCLUIDO => 'Concluído',
            self::CANCELADO => 'Cancelado',
            self::TRANSFERIDO => 'Transferido',
        };
    }

    public function podeTransitarPara(self $estado): bool
    {
        return match ($this) {
            self::ACTIVO => in_array($estado, [
                self::CONCLUIDO,
                self::CANCELADO,
                self::TRANSFERIDO,
            ], true),

            self::CONCLUIDO,
            self::CANCELADO,
            self::TRANSFERIDO => false,
        };
    }
}
