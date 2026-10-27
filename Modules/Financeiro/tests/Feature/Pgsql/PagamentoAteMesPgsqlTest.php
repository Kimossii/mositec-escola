<?php

namespace Modules\Financeiro\Tests\Feature\Pgsql;

use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Modules\Financeiro\Enums\EstadoCobranca;
use Modules\Financeiro\Enums\TipoMulta;
use Modules\Financeiro\Models\Propina;
use Modules\Financeiro\Models\PropinaMulta;
use Modules\Financeiro\Services\DistribuicaoDePagamentoService;
use Modules\Financeiro\Tests\Concerns\ComDadosAcademicosFinanceiro;
use Modules\Financeiro\Tests\Concerns\ComMultasFinanceiro;
use Modules\Financeiro\Tests\Concerns\ComPagamentosFinanceiro;
use Modules\Financeiro\Tests\Concerns\ComPropinasFinanceiro;
use PHPUnit\Framework\Attributes\Group;
use Tests\PgsqlTestCase;

/**
 * «Pagar até [mês]» sob PostgreSQL: os índices únicos parciais de propinas (cobrança dupla, camada 1) e a sintaxe
 * de datas do motor (`data_limite + ?::integer`) só são exercitados a sério contra o PostgreSQL.
 */
#[Group('pgsql')]
class PagamentoAteMesPgsqlTest extends PgsqlTestCase
{
    use ComDadosAcademicosFinanceiro;
    use ComMultasFinanceiro;
    use ComPagamentosFinanceiro;
    use ComPropinasFinanceiro;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /** Set–Dez pagos; Jan e Fev em dívida. */
    private function cenarioComJanEFevEmDivida(): array
    {
        Carbon::setTestNow(CarbonImmutable::parse('2027-02-15 10:00:00', 'UTC'));
        ['plano' => $plano, 'matricula' => $matricula] = $this->cenarioPropinas();
        $this->regras(antecipado: true);

        foreach (range(1, 4) as $ordem) {
            $this->comEstado($this->propina($matricula, $plano, $ordem), EstadoCobranca::PAGA);
        }

        return ['plano' => $plano, 'matricula' => $matricula, 'jan' => $this->propina($matricula, $plano, 5), 'fev' => $this->propina($matricula, $plano, 6)];
    }

    public function test_pagar_ate_abril_gera_e_cobra_jan_a_abr_sem_duplicar(): void
    {
        ['matricula' => $matricula] = $this->cenarioComJanEFevEmDivida();

        $pagamento = $this->pagar($matricula, 10_000_000, null, 0, '2027-02-15', null, 2027, 4);

        $periodos = ['2027-01-01', '2027-02-01', '2027-03-01', '2027-04-01'];
        $this->assertSame(8, Propina::query()->count());
        $this->assertSame(4, Propina::query()->whereIn('periodo_inicio', $periodos)->where('estado', EstadoCobranca::PAGA->value)->count());
        $this->assertSame(0, Propina::query()->whereIn('periodo_inicio', ['2027-05-01', '2027-06-01'])->count());

        $pagamento->load('distribuicoes.propina');
        $this->assertSame($periodos, $pagamento->distribuicoes->map(fn ($d) => $d->propina->periodo_inicio->toDateString())->all());

        // Repetir não duplica (o índice único parcial protege contra a cobrança dupla).
        $this->pagar($matricula, 100_000, null, 0, '2027-02-15', null, 2027, 4);
        $this->assertSame(8, Propina::query()->count());
    }

    public function test_propina_em_atraso_mostra_multa_na_previa_e_no_pagamento(): void
    {
        Carbon::setTestNow(CarbonImmutable::parse('2026-10-16 10:00:00', 'UTC'));
        ['plano' => $plano, 'matricula' => $matricula] = $this->cenarioPropinas();
        $this->regras();
        $this->multaActiva([[1, TipoMulta::PERCENTAGEM, 500]]); // 5%, L1 = 16/09
        $set = $this->propina($matricula, $plano, 1);

        $dto = $this->pedidoPagamento(2_625_000, null, 0, '2026-10-16');
        $previa = app(DistribuicaoDePagamentoService::class)->preVisualizarPagamento($matricula->fresh(), $dto);
        $this->assertSame(125_000, $previa->linhas[0]['multa_pendente_antes']);
        $this->assertSame(0, PropinaMulta::count());

        $pagamento = $this->pagar($matricula, 2_625_000, null, 0, '2026-10-16');
        $this->assertSame(125_000, PropinaMulta::sole()->valor->unidadesMenores());
        $this->assertSame(2_500_000, $set->fresh()->valor_pago->unidadesMenores());

        $pagamento->load('multas');
        $total = $pagamento->distribuicoes->sum(fn ($d) => $d->valor->unidadesMenores()) + $pagamento->multas->sum(fn ($d) => $d->valor->unidadesMenores());
        $this->assertSame(2_625_000, $total);
    }

    public function test_pagar_ate_um_mes_futuro_sem_antecipado_e_recusado(): void
    {
        Carbon::setTestNow(CarbonImmutable::parse('2026-10-16 10:00:00', 'UTC'));
        ['matricula' => $matricula] = $this->cenarioPropinas();
        $this->regras(antecipado: false);

        try {
            $this->pagar($matricula, 2_500_000, null, 0, '2026-10-16', null, 2027, 4);
            $this->fail('Devia recusar «Pagar até» um mês futuro sem pagamento antecipado.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('ate_mes', $e->errors());
        }
    }
}
