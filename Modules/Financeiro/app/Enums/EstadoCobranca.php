<?php

namespace Modules\Financeiro\Enums;

use Carbon\CarbonInterface;
use LogicException;
use Modules\Financeiro\Contracts\CobrancaResolvivel;

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

    /**
     * Estado mostrado e filtrado de uma cobrança (spec Propinas §4), por esta precedência:
     * 1. Cancelada ou Anulada (persistidos, terminais);
     * 2. Paga (nada em dívida);
     * 3. Em Atraso: saldo > 0 e hoje depois do limite (vencimento + tolerância, dias corridos);
     * 4. Parcialmente Paga (algo pago);
     * 5. Pendente: período ainda por começar e nada pago;
     * 6. Em Aberto.
     * Compara dias civis, nunca horas. A tradução SQL é Propina::scopeComEstadoResolvido: as duas são
     * testadas juntas e só coincidem porque o estado persistido é sempre coerente com o valor pago
     * (garantido por RecalcularPropina, pelo model e, em PostgreSQL, por CHECK).
     */
    public static function resolver(CobrancaResolvivel $cobranca, CarbonInterface $hoje): self
    {
        $persistido = $cobranca->estadoPersistido();

        if (! $persistido->persistido()) {
            throw new LogicException('Pendente e Em Atraso são estados derivados e nunca se gravam.');
        }

        if ($persistido === self::CANCELADA || $persistido === self::ANULADA) {
            return $persistido;
        }

        $devido = $cobranca->valorDevido()->unidadesMenores();
        $recebido = $cobranca->valorRecebido()->unidadesMenores();
        $dia = $hoje->toDateString();

        return match (true) {
            $recebido >= $devido => self::PAGA,
            $dia > $cobranca->limiteSemAtraso()->toDateString() => self::EM_ATRASO,
            $recebido > 0 => self::PARCIALMENTE_PAGA,
            $cobranca->inicioDoPeriodo()->toDateString() > $dia => self::PENDENTE,
            default => self::EM_ABERTO,
        };
    }
}
