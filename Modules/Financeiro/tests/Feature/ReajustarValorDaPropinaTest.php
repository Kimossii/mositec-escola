<?php

namespace Modules\Financeiro\Tests\Feature;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;
use Modules\Financeiro\Enums\EstadoCobranca;
use Modules\Financeiro\Models\Propina;
use Modules\Financeiro\Services\RecalcularPropina;
use Modules\Financeiro\Support\Dinheiro;
use Modules\Financeiro\Tests\Concerns\ComDadosAcademicosFinanceiro;
use Modules\Financeiro\Tests\Concerns\ComPropinasFinanceiro;
use Modules\Financeiro\Tests\Concerns\FontePagamentoFalsa;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ReajustarValorDaPropinaTest extends TestCase
{
    use ComDadosAcademicosFinanceiro;
    use ComPropinasFinanceiro;
    use RefreshDatabase;

    private FontePagamentoFalsa $fonte;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(CarbonImmutable::parse('2026-10-16 10:00:00', 'UTC'));
        $this->fonte = $this->fontePagamentoFalsa();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function aberta(): Propina
    {
        ['plano' => $plano, 'matricula' => $matricula] = $this->cenarioPropinas();

        return $this->propina($matricula, $plano, 5);
    }

    /** Parcialmente Paga com 10.000,00 recebidos (cache E contribuição coerentes). */
    private function parcial(): Propina
    {
        $propina = $this->comEstado($this->aberta(), EstadoCobranca::PARCIALMENTE_PAGA, 1_000_000);
        $this->fonte->definir($propina, [[1_000_000, '2026-10-01']]);

        return $propina;
    }

    private function reajustar(Propina $propina, int $valor): Propina
    {
        return DB::transaction(fn () => app(RecalcularPropina::class)->reajustarValor($propina, Dinheiro::deUnidadesMenores($valor)));
    }

    public function test_subida_numa_propina_em_aberto_muda_so_o_valor(): void
    {
        $propina = $this->reajustar($this->aberta(), 3_000_000)->refresh();

        $this->assertSame(3_000_000, $propina->valor->unidadesMenores());
        $this->assertSame(2_500_000, $propina->valor_original->unidadesMenores());
        $this->assertSame(0, $propina->valor_pago->unidadesMenores());
        $this->assertSame(EstadoCobranca::EM_ABERTO, $propina->estado);
        $this->assertNull($propina->capital_liquidado_em);
    }

    public function test_parcial_com_subida_preserva_o_pago_e_recalcula_o_saldo(): void
    {
        $propina = $this->reajustar($this->parcial(), 3_000_000)->refresh();

        $this->assertSame(3_000_000, $propina->valor->unidadesMenores());
        $this->assertSame(1_000_000, $propina->valor_pago->unidadesMenores());
        $this->assertSame(2_000_000, $propina->saldo()->unidadesMenores());
        $this->assertSame(EstadoCobranca::PARCIALMENTE_PAGA, $propina->estado);
    }

    public function test_parcial_com_descida_acima_do_pago_continua_parcial(): void
    {
        $propina = $this->reajustar($this->parcial(), 1_500_000)->refresh();

        $this->assertSame(1_500_000, $propina->valor->unidadesMenores());
        $this->assertSame(500_000, $propina->saldo()->unidadesMenores());
        $this->assertSame(EstadoCobranca::PARCIALMENTE_PAGA, $propina->estado);
    }

    #[DataProvider('descidasAteAoPago')]
    public function test_descida_ate_ou_abaixo_do_pago_fica_no_pago_e_passa_a_paga_sem_devolucao(int $pretendido): void
    {
        $propina = $this->reajustar($this->parcial(), $pretendido)->refresh();

        $this->assertSame(1_000_000, $propina->valor->unidadesMenores(), 'valor = valor_pago (sem devolução)');
        $this->assertSame(1_000_000, $propina->valor_pago->unidadesMenores());
        $this->assertSame(EstadoCobranca::PAGA, $propina->estado);
        $this->assertSame('2026-10-01', $propina->capital_liquidado_em?->toDateString());
    }

    public static function descidasAteAoPago(): array
    {
        return ['igual ao pago' => [1_000_000], 'abaixo do pago' => [800_000]];
    }

    #[DataProvider('estadosQueNaoSeReajustam')]
    public function test_paga_cancelada_e_anulada_nunca_se_reajustam(EstadoCobranca $estado): void
    {
        $propina = $this->comEstado($this->aberta(), $estado);

        try {
            $this->reajustar($propina, 3_000_000);
            $this->fail('Devia recusar.');
        } catch (LogicException $e) {
            $this->assertStringContainsString('Só se reajusta o valor de uma propina Em Aberto ou Parcialmente Paga', $e->getMessage());
        }

        $this->assertSame(2_500_000, $propina->refresh()->valor->unidadesMenores());
    }

    public static function estadosQueNaoSeReajustam(): array
    {
        return ['Paga' => [EstadoCobranca::PAGA], 'Cancelada' => [EstadoCobranca::CANCELADA], 'Anulada' => [EstadoCobranca::ANULADA]];
    }

    public function test_cache_de_valor_pago_divergente_recusa_e_nada_muda(): void
    {
        // Cache 10.000,00 sem contribuições: reajustar mudaria o recebido, o que o spec proíbe.
        $propina = $this->comEstado($this->aberta(), EstadoCobranca::PARCIALMENTE_PAGA, 1_000_000);

        try {
            $this->reajustar($propina, 3_000_000);
            $this->fail('Devia recusar.');
        } catch (LogicException $e) {
            $this->assertStringContainsString('não coincide com os pagamentos', $e->getMessage());
        }

        $propina->refresh();
        $this->assertSame(2_500_000, $propina->valor->unidadesMenores());
        $this->assertSame(1_000_000, $propina->valor_pago->unidadesMenores());
    }

    public function test_valor_pretendido_tem_de_ser_positivo(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->reajustar($this->aberta(), 0);
    }

    public function test_exige_transaccao_aberta(): void
    {
        $propina = $this->aberta();

        // O RefreshDatabase envolve o teste numa transacção: sai-se dela para provar a recusa (como no RecalcularPropinaTest).
        DB::rollBack();
        $this->assertSame(0, DB::transactionLevel());

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('exige uma transacção aberta');

        app(RecalcularPropina::class)->reajustarValor($propina, Dinheiro::deUnidadesMenores(3_000_000));
    }
}
