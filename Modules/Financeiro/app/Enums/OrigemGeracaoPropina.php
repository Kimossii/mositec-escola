<?php

namespace Modules\Financeiro\Enums;

/**
 * Como nasceu uma propina (auditoria e sinalização de geração tardia, §8.1/Q4 do plano de fases).
 * RETROACTIVA = períodos anteriores ao mês da matrícula ou já vencidos, gerados manualmente com
 * `propina.ajustar` e motivo obrigatório.
 */
enum OrigemGeracaoPropina: int
{
    case MATRICULA = 1;
    case COMANDO = 2;
    case MANUAL = 3;
    case RETROACTIVA = 4;

    public function label(): string
    {
        return match ($this) {
            self::MATRICULA => 'Matrícula',
            self::COMANDO => 'Comando automático',
            self::MANUAL => 'Manual',
            self::RETROACTIVA => 'Retroactiva',
        };
    }

    public function exigeMotivo(): bool
    {
        return $this === self::RETROACTIVA;
    }
}
