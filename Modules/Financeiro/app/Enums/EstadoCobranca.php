<?php

namespace Modules\Financeiro\Enums;

/**
 * Estados de uma cobrança. PENDENTE (período ainda não iniciado) e EM_ATRASO são
 * derivados pela data e nunca persistidos; os restantes cinco são a coluna `estado`.
 * As transições entre EM_ABERTO, PARCIALMENTE_PAGA e PAGA são automáticas (recálculo
 * do valor pago); só CANCELADA e ANULADA são acções manuais, ambas terminais.
 */
enum EstadoCobranca: int
{
    case EM_ABERTO = 1;
    case PARCIALMENTE_PAGA = 2;
    case PAGA = 3;
    case CANCELADA = 4;
    case ANULADA = 5;
    case PENDENTE = 6;
    case EM_ATRASO = 7;

    public function label(): string
    {
        return match ($this) {
            self::EM_ABERTO => 'Em Aberto',
            self::PARCIALMENTE_PAGA => 'Parcialmente Paga',
            self::PAGA => 'Paga',
            self::CANCELADA => 'Cancelada',
            self::ANULADA => 'Anulada',
            self::PENDENTE => 'Pendente',
            self::EM_ATRASO => 'Em Atraso',
        };
    }

    public function persistido(): bool
    {
        return ! in_array($this, [self::PENDENTE, self::EM_ATRASO], true);
    }

    /**
     * @return list<self>
     */
    public function transicoesPermitidas(): array
    {
        return match ($this) {
            self::EM_ABERTO => [self::PARCIALMENTE_PAGA, self::PAGA, self::CANCELADA, self::ANULADA],
            self::PARCIALMENTE_PAGA => [self::EM_ABERTO, self::PAGA],
            self::PAGA => [self::EM_ABERTO, self::PARCIALMENTE_PAGA],
            self::CANCELADA, self::ANULADA, self::PENDENTE, self::EM_ATRASO => [],
        };
    }

    public function podeTransitarPara(self $destino): bool
    {
        return in_array($destino, $this->transicoesPermitidas(), true);
    }
}
