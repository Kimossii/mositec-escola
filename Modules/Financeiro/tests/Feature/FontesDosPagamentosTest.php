<?php

namespace Modules\Financeiro\Tests\Feature;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;
use Modules\Financeiro\Actions\EliminarMetodoPagamentoAction;
use Modules\Financeiro\Enums\EstadoCobranca;
use Modules\Financeiro\Enums\EstadoCredito;
use Modules\Financeiro\Enums\EstadoPagamento;
use Modules\Financeiro\Enums\EstadoUtilizacaoCredito;
use Modules\Financeiro\Models\ConfiguracaoMonetaria;
use Modules\Financeiro\Models\Credito;
use Modules\Financeiro\Models\Pagamento;
use Modules\Financeiro\Models\Propina;
use Modules\Financeiro\Services\RecalcularCredito;
use Modules\Financeiro\Services\RecalcularPropina;
use Modules\Financeiro\Support\FontesDePagamentoDePropina;
use Modules\Financeiro\Support\HistoricosDePagamentoDaPropina;
use Modules\Financeiro\Support\ReferenciasFinanceiras;
use Modules\Financeiro\Tests\Concerns\ComDadosAcademicosFinanceiro;
use Modules\Financeiro\Tests\Concerns\ComPagamentosFinanceiro;
use Modules\Financeiro\Tests\Concerns\ComPropinasFinanceiro;
use Tests\TestCase;

class FontesDosPagamentosTest extends TestCase
{
    use ComDadosAcademicosFinanceiro;
    use ComPagamentosFinanceiro;
    use ComPropinasFinanceiro;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(CarbonImmutable::parse('2026-10-16 10:00:00', 'UTC'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function recalcular(Propina $propina): Propina
    {
        return DB::transaction(fn () => app(RecalcularPropina::class)->executar($propina));
    }

    private function anularDirecto(Pagamento $pagamento): void
    {
        $pagamento->forceFill(['estado' => EstadoPagamento::ANULADO, 'motivo_anulacao' => 'Teste', 'anulado_em' => now()])->save();
    }

    public function test_as_fontes_reais_estao_registadas_e_comecam_vazias(): void
    {
        ['plano' => $plano, 'matricula' => $matricula] = $this->cenarioPropinas();
        $propina = $this->propina($matricula, $plano, 2);

        $this->assertSame([], app(FontesDePagamentoDePropina::class)->contribuicoes($propina));
        $this->assertFalse(app(HistoricosDePagamentoDaPropina::class)->temHistorico($propina));
    }

    public function test_distribuicoes_confirmadas_e_utilizacoes_activas_contam_com_a_sua_data(): void
    {
        ['plano' => $plano, 'matricula' => $matricula] = $this->cenarioPropinas();
        $propina = $this->propina($matricula, $plano, 2); // 25.000,00
        $pagamento = $this->pagamentoDirecto($matricula, 1_000_000, ['data_pagamento' => '2026-10-05']);
        $this->distribuicaoDirecta($pagamento, $propina, 1_000_000);
        $origem = $this->pagamentoDirecto($matricula, 1_500_000, ['data_pagamento' => '2026-09-20']);
        $credito = $this->creditoDirecto($origem, 1_500_000);
        $this->utilizacaoDirecta($credito, $propina, 1_500_000, '2026-10-12');

        $contribuicoes = app(FontesDePagamentoDePropina::class)->contribuicoes($propina);

        $this->assertSame([[1_000_000, '2026-10-05'], [1_500_000, '2026-10-12']], array_map(
            fn ($c) => [$c->valor->unidadesMenores(), $c->data->toDateString()],
            $contribuicoes,
        ));

        $recalculada = $this->recalcular($propina);
        $this->assertSame(EstadoCobranca::PAGA, $recalculada->estado);
        $this->assertSame(2_500_000, $recalculada->valor_pago->unidadesMenores());
        $this->assertSame('2026-10-12', $recalculada->capital_liquidado_em->toDateString(), 'A contribuição que levou o saldo a 0.');
        $this->assertPropinasCoerentes();
    }

    public function test_pagamento_anulado_e_utilizacao_anulada_nao_contam_mas_ficam_no_historico(): void
    {
        ['plano' => $plano, 'matricula' => $matricula] = $this->cenarioPropinas();
        $propina = $this->propina($matricula, $plano, 2);
        $pagamento = $this->pagamentoDirecto($matricula, 1_000_000);
        $this->distribuicaoDirecta($pagamento, $propina, 1_000_000);
        $this->assertSame(EstadoCobranca::PARCIALMENTE_PAGA, $this->recalcular($propina)->estado);

        $this->anularDirecto($pagamento);

        $recalculada = $this->recalcular($propina);
        $this->assertSame(EstadoCobranca::EM_ABERTO, $recalculada->estado);
        $this->assertSame(0, $recalculada->valor_pago->unidadesMenores());
        $this->assertNull($recalculada->capital_liquidado_em);
        $this->assertTrue(app(HistoricosDePagamentoDaPropina::class)->temHistorico($propina));
        $this->assertPropinasCoerentes();
    }

    public function test_so_uma_utilizacao_de_credito_tambem_e_historico(): void
    {
        ['plano' => $plano, 'matricula' => $matricula] = $this->cenarioPropinas();
        $propina = $this->propina($matricula, $plano, 2);
        $credito = $this->creditoDirecto($this->pagamentoDirecto($matricula, 500_000), 500_000);
        $utilizacao = $this->utilizacaoDirecta($credito, $propina, 500_000);
        $utilizacao->forceFill(['estado' => EstadoUtilizacaoCredito::ANULADA, 'motivo_anulacao' => 'Engano', 'anulado_em' => now()])->save();

        $this->assertSame([], app(FontesDePagamentoDePropina::class)->contribuicoes($propina));
        $this->assertTrue(app(HistoricosDePagamentoDaPropina::class)->temHistorico($propina));
    }

    public function test_metodo_com_pagamentos_nao_se_elimina_e_a_moeda_fica_presa(): void
    {
        ['matricula' => $matricula] = $this->cenarioPropinas();
        $metodo = $this->metodoPagamento('Transferência');
        $livre = $this->metodoPagamento('Cheque');
        $this->pagamentoDirecto($matricula, 100, ['metodo_pagamento_id' => $metodo->id]);

        $referencias = app(ReferenciasFinanceiras::class);
        $this->assertTrue($referencias->existeReferenciaA($metodo));
        $this->assertFalse($referencias->existeReferenciaA($livre));
        $this->assertTrue($referencias->existeReferenciaA(ConfiguracaoMonetaria::doTenant()));

        $this->expectException(ValidationException::class);
        app(EliminarMetodoPagamentoAction::class)->executar($metodo);
    }

    public function test_recalcular_credito_segue_as_utilizacoes_activas_e_anular_exige_credito_por_usar(): void
    {
        ['plano' => $plano, 'matricula' => $matricula] = $this->cenarioPropinas();
        $credito = $this->creditoDirecto($this->pagamentoDirecto($matricula, 500_000), 500_000);
        $utilizacao = $this->utilizacaoDirecta($credito, $this->propina($matricula, $plano, 2), 500_000);
        $recalcular = app(RecalcularCredito::class);

        $credito = DB::transaction(fn () => $recalcular->executar($credito));
        $this->assertSame(EstadoCredito::ESGOTADO, $credito->estado);
        $this->assertSame(0, $credito->saldo()->unidadesMenores());

        try {
            DB::transaction(fn () => $recalcular->anular($credito));
            $this->fail('Um crédito utilizado não se anula.');
        } catch (LogicException) {
            $this->addToAssertionCount(1);
        }

        $utilizacao->forceFill(['estado' => EstadoUtilizacaoCredito::ANULADA, 'motivo_anulacao' => 'Engano', 'anulado_em' => now()])->save();
        $credito = DB::transaction(fn () => $recalcular->executar($credito));
        $this->assertSame(EstadoCredito::DISPONIVEL, $credito->estado);
        $this->assertSame(500_000, $credito->saldo()->unidadesMenores());

        $credito = DB::transaction(fn () => $recalcular->anular($credito));
        $this->assertSame(EstadoCredito::ANULADO, $credito->estado);
        $this->assertSame(EstadoCredito::ANULADO, DB::transaction(fn () => $recalcular->executar($credito))->estado, 'Anulado é terminal.');
    }

    public function test_recalcular_credito_exige_transaccao(): void
    {
        ['matricula' => $matricula] = $this->cenarioPropinas();
        $credito = $this->creditoDirecto($this->pagamentoDirecto($matricula, 500_000), 500_000);

        // O RefreshDatabase envolve o teste numa transacção: sai-se dela para provar a recusa (como no RecalcularPropinaTest).
        DB::rollBack();
        $this->assertSame(0, DB::transactionLevel());

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('exige uma transacção aberta');
        app(RecalcularCredito::class)->executar($credito);
    }

    public function test_credito_de_outro_tenant_nao_se_recalcula(): void
    {
        ['matricula' => $matricula] = $this->cenarioPropinas();
        $credito = $this->creditoDirecto($this->pagamentoDirecto($matricula, 500_000), 500_000);
        $outro = $this->criarTenant('MOSI-000002', 'Escola B', 'b.localhost');

        $this->noTenant($outro, function () use ($credito) {
            $this->expectException(LogicException::class);
            DB::transaction(fn () => app(RecalcularCredito::class)->executar($credito));
        });
    }
}
