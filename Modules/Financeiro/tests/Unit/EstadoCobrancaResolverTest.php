<?php

namespace Modules\Financeiro\Tests\Unit;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use LogicException;
use Modules\Financeiro\Contracts\CobrancaResolvivel;
use Modules\Financeiro\Enums\EstadoCobranca;
use Modules\Financeiro\Support\Dinheiro;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Cobrança de referência: período a começar a 01/10/2026, limite sem atraso a 15/10/2026, valor 100.
 */
class EstadoCobrancaResolverTest extends TestCase
{
    private function cobranca(EstadoCobranca $estado, int $pago, int $valor = 100, string $inicio = '2026-10-01', string $limite = '2026-10-15'): CobrancaResolvivel
    {
        return new class($estado, $valor, $pago, $inicio, $limite) implements CobrancaResolvivel {
            public function __construct(
                private EstadoCobranca $estado,
                private int $valor,
                private int $pago,
                private string $inicio,
                private string $limite,
            ) {
            }

            public function estadoPersistido(): EstadoCobranca
            {
                return $this->estado;
            }

            public function valorDevido(): Dinheiro
            {
                return Dinheiro::deUnidadesMenores($this->valor);
            }

            public function valorRecebido(): Dinheiro
            {
                return Dinheiro::deUnidadesMenores($this->pago);
            }

            public function inicioDoPeriodo(): CarbonInterface
            {
                return CarbonImmutable::parse($this->inicio);
            }

            public function limiteSemAtraso(): CarbonInterface
            {
                return CarbonImmutable::parse($this->limite);
            }
        };
    }

    private function resolver(CobrancaResolvivel $cobranca, string $hoje): EstadoCobranca
    {
        return EstadoCobranca::resolver($cobranca, CarbonImmutable::parse($hoje));
    }

    public function test_cancelada_e_anulada_sao_terminais_mesmo_muito_depois_do_limite(): void
    {
        $this->assertSame(EstadoCobranca::CANCELADA, $this->resolver($this->cobranca(EstadoCobranca::CANCELADA, 0), '2027-12-31'));
        $this->assertSame(EstadoCobranca::ANULADA, $this->resolver($this->cobranca(EstadoCobranca::ANULADA, 0), '2027-12-31'));
        $this->assertSame(EstadoCobranca::CANCELADA, $this->resolver($this->cobranca(EstadoCobranca::CANCELADA, 0), '2026-09-01'));
    }

    public function test_paga_antes_do_inicio_e_depois_do_limite(): void
    {
        $this->assertSame(EstadoCobranca::PAGA, $this->resolver($this->cobranca(EstadoCobranca::PAGA, 100), '2026-09-01'));
        $this->assertSame(EstadoCobranca::PAGA, $this->resolver($this->cobranca(EstadoCobranca::PAGA, 100), '2027-12-31'));
    }

    public function test_fronteira_da_tolerancia_o_proprio_dia_limite_ainda_nao_e_atraso(): void
    {
        $this->assertSame(EstadoCobranca::EM_ABERTO, $this->resolver($this->cobranca(EstadoCobranca::EM_ABERTO, 0), '2026-10-15'));
        $this->assertSame(EstadoCobranca::EM_ATRASO, $this->resolver($this->cobranca(EstadoCobranca::EM_ABERTO, 0), '2026-10-16'));
    }

    public function test_em_atraso_prevalece_sobre_pagamento_parcial(): void
    {
        $this->assertSame(EstadoCobranca::PARCIALMENTE_PAGA, $this->resolver($this->cobranca(EstadoCobranca::PARCIALMENTE_PAGA, 40), '2026-10-15'));
        $this->assertSame(EstadoCobranca::EM_ATRASO, $this->resolver($this->cobranca(EstadoCobranca::PARCIALMENTE_PAGA, 40), '2026-10-16'));
    }

    public function test_pendente_antes_do_inicio_e_em_aberto_no_proprio_dia_do_inicio(): void
    {
        $this->assertSame(EstadoCobranca::PENDENTE, $this->resolver($this->cobranca(EstadoCobranca::EM_ABERTO, 0), '2026-09-30'));
        $this->assertSame(EstadoCobranca::EM_ABERTO, $this->resolver($this->cobranca(EstadoCobranca::EM_ABERTO, 0), '2026-10-01'));
    }

    public function test_pagamento_antecipado_parcial_e_parcialmente_paga_e_nunca_pendente(): void
    {
        $this->assertSame(EstadoCobranca::PARCIALMENTE_PAGA, $this->resolver($this->cobranca(EstadoCobranca::PARCIALMENTE_PAGA, 10), '2026-09-01'));
    }

    public function test_as_horas_do_dia_sao_ignoradas(): void
    {
        $cobranca = $this->cobranca(EstadoCobranca::EM_ABERTO, 0);

        $this->assertSame(EstadoCobranca::EM_ABERTO, EstadoCobranca::resolver($cobranca, CarbonImmutable::parse('2026-10-15 23:59:59')));
        $this->assertSame(EstadoCobranca::EM_ATRASO, EstadoCobranca::resolver($cobranca, CarbonImmutable::parse('2026-10-16 00:00:01')));
        $this->assertSame(EstadoCobranca::PENDENTE, EstadoCobranca::resolver($cobranca, CarbonImmutable::parse('2026-09-30 23:59:59')));
    }

    public function test_o_estado_mostrado_deriva_dos_montantes_e_das_datas(): void
    {
        // O persistido só decide Cancelada/Anulada; o resto vem do valor pago e das datas (spec §4).
        $this->assertSame(EstadoCobranca::PAGA, $this->resolver($this->cobranca(EstadoCobranca::EM_ABERTO, 100), '2026-10-05'));
    }

    #[DataProvider('derivados')]
    public function test_estado_derivado_como_persistido_e_recusado(EstadoCobranca $estado): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Pendente e Em Atraso são estados derivados e nunca se gravam.');

        $this->resolver($this->cobranca($estado, 0), '2026-10-05');
    }

    public static function derivados(): array
    {
        return ['pendente' => [EstadoCobranca::PENDENTE], 'em atraso' => [EstadoCobranca::EM_ATRASO]];
    }
}
