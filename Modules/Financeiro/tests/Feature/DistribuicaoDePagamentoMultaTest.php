<?php

namespace Modules\Financeiro\Tests\Feature;

use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Modules\Financeiro\Actions\AplicarCreditoAction;
use Modules\Financeiro\DTO\PlanoDePagamentoDTO;
use Modules\Financeiro\Enums\EstadoCobranca;
use Modules\Financeiro\Models\CreditoUtilizacao;
use Modules\Financeiro\Models\PagamentoMulta;
use Modules\Financeiro\Models\PagamentoPropina;
use Modules\Financeiro\Models\Propina;
use Modules\Financeiro\Models\PropinaMulta;
use Modules\Financeiro\Services\DistribuicaoDePagamentoService;
use Modules\Financeiro\Services\PagamentoConsultaService;
use Modules\Financeiro\Support\BloqueioDePropinas;
use Modules\Financeiro\Support\HistoricoDosPagamentos;
use Modules\Financeiro\Tests\Concerns\ComDadosAcademicosFinanceiro;
use Modules\Financeiro\Tests\Concerns\ComMultasFinanceiro;
use Modules\Financeiro\Tests\Concerns\ComPagamentosFinanceiro;
use Modules\Financeiro\Tests\Concerns\ComPropinasFinanceiro;
use Modules\Matricula\Models\Matricula;
use Tests\TestCase;

/**
 * Motor de distribuição com multas (spec Pagamentos §4, Propinas §15.2): imputação capital → multa.
 * Hoje = 16/10/2026; propinas mensais a 25.000,00.
 */
class DistribuicaoDePagamentoMultaTest extends TestCase
{
    use ComDadosAcademicosFinanceiro;
    use ComMultasFinanceiro;
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

    /** @return array{0: Matricula, 1: Propina, 2: PropinaMulta} */
    private function propinaComMulta(int $valorMulta = 25_000): array
    {
        ['plano' => $plano, 'matricula' => $matricula] = $this->cenarioPropinas();
        $propina = $this->propina($matricula, $plano);

        return [$matricula, $propina, $this->multa($propina, $valorMulta)];
    }

    private function previa(Matricula $matricula, int $valor, ?array $linhas = null, int $credito = 0, string $data = '2026-10-16'): PlanoDePagamentoDTO
    {
        return app(DistribuicaoDePagamentoService::class)->preVisualizarPagamento($matricula->fresh(), $this->pedidoPagamento($valor, $linhas, $credito, $data));
    }

    private function assertRecusado(string $campo, Closure $operacao): void
    {
        try {
            $operacao();
            $this->fail("Devia recusar em {$campo}.");
        } catch (ValidationException $e) {
            $this->assertArrayHasKey($campo, $e->errors(), json_encode($e->errors()));
        }
    }

    public function test_capital_e_pago_antes_da_multa_na_mesma_linha(): void
    {
        $this->regras();
        [$matricula, $propina, $multa] = $this->propinaComMulta();

        $plano = $this->previa($matricula, 2_525_000, [[$propina, 2_525_000]]);

        $this->assertCount(1, $plano->linhas);
        $linha = $plano->linhas[0];
        $this->assertSame(2_500_000, $linha['dinheiro'], 'capital com dinheiro');
        $this->assertSame(25_000, $linha['multa_dinheiro'], 'multa depois do capital');
        $this->assertSame(0, $linha['saldo_depois']);
        $this->assertSame(0, $linha['multa_pendente_depois']);
        $this->assertSame([['propina_id' => $propina->id, 'valor' => 2_500_000]], $plano->distribuicoes());
        $this->assertSame([['propina_multa_id' => $multa->id, 'valor' => 25_000]], $plano->distribuicoesMulta());
    }

    public function test_credito_cobre_o_capital_e_o_dinheiro_cobre_a_multa(): void
    {
        $this->regras();
        [$matricula, $propina, $multa] = $this->propinaComMulta();
        $credito = $this->creditoDirecto($this->pagamentoDirecto($matricula, 2_500_000), 2_500_000);

        $plano = $this->previa($matricula, 25_000, [[$propina, 2_525_000]], 2_500_000);

        $linha = $plano->linhas[0];
        $this->assertSame(2_500_000, $linha['credito'], 'crédito cobre o capital');
        $this->assertSame(0, $linha['dinheiro']);
        $this->assertSame(0, $linha['multa_credito'], 'crédito já esgotado no capital');
        $this->assertSame(25_000, $linha['multa_dinheiro'], 'dinheiro paga a multa');

        $this->assertCount(1, $plano->utilizacoes);
        $this->assertSame($credito->id, $plano->utilizacoes[0]['credito_id']);
        $this->assertSame($propina->id, $plano->utilizacoes[0]['propina_id']);
        $this->assertNull($plano->utilizacoes[0]['propina_multa_id'], 'crédito usado na propina, não na multa');
    }

    public function test_sem_parcial_liquida_capital_e_multa_por_inteiro_ou_nao_toca_e_nunca_salta_a_mais_antiga(): void
    {
        $this->regras(parcial: false);
        ['plano' => $plano, 'matricula' => $matricula] = $this->cenarioPropinas();
        $set = $this->propina($matricula, $plano, 1);
        $this->multa($set, 25_000);
        $this->propina($matricula, $plano, 2); // Out, sem multa

        $insuficiente = $this->previa($matricula, 2_524_999);
        $this->assertSame([], $insuficiente->linhas, 'Insuficiente: não toca em nada.');
        $this->assertSame(2_524_999, $insuficiente->excedente);

        $exacto = $this->previa($matricula, 2_525_000);
        $this->assertSame([$set->id], array_column($exacto->linhas, 'propina_id'), 'Paga Set (capital+multa) e pára.');
        $this->assertSame(2_500_000, $exacto->linhas[0]['dinheiro']);
        $this->assertSame(25_000, $exacto->linhas[0]['multa_dinheiro']);
        $this->assertSame(0, $exacto->excedente);
    }

    public function test_propina_paga_com_multa_pendente_aparece_pagavel_e_paga_a_multa(): void
    {
        $this->regras();
        ['plano' => $plano, 'matricula' => $matricula] = $this->cenarioPropinas();
        $propina = $this->propina($matricula, $plano);

        $this->pagar($matricula, 2_500_000, [[$propina, 2_500_000]]); // capital pago de verdade
        $this->assertSame(EstadoCobranca::PAGA, $propina->fresh()->estado);

        $multa = $this->multa($propina, 25_000);

        $plano = $this->previa($matricula, 25_000);
        $this->assertSame([$propina->id], array_column($plano->linhas, 'propina_id'), 'A propina Paga com multa é pagável.');
        $this->assertSame(0, $plano->linhas[0]['dinheiro']);
        $this->assertSame(25_000, $plano->linhas[0]['multa_dinheiro']);

        $this->pagar($matricula, 25_000);
        $this->assertSame(25_000, $multa->fresh()->valor_pago->unidadesMenores());
        $this->assertSame(EstadoCobranca::PAGA, $propina->fresh()->estado, 'O estado da propina depende só do capital.');
        $this->assertPagamentosCoerentes();
    }

    public function test_anular_pagamento_reabre_o_capital_e_a_multa(): void
    {
        $this->regras();
        [$matricula, $propina, $multa] = $this->propinaComMulta();

        $pagamento = $this->pagar($matricula, 2_525_000, [[$propina, 2_525_000]]);
        $this->assertSame(EstadoCobranca::PAGA, $propina->fresh()->estado);
        $this->assertSame(25_000, $multa->fresh()->valor_pago->unidadesMenores());

        $this->anularPagamento($pagamento);

        $this->assertSame(EstadoCobranca::EM_ABERTO, $propina->fresh()->estado);
        $this->assertSame(0, $multa->fresh()->valor_pago->unidadesMenores());
        $this->assertPagamentosCoerentes();
    }

    public function test_invariante_valor_igual_a_distribuicoes_mais_multas_mais_credito(): void
    {
        $this->regras(parcial: true);
        [$matricula, $propina] = $this->propinaComMulta();
        $pagamento = $this->pagar($matricula, 2_525_000, [[$propina, 2_525_000]]);

        $distribuicoes = (int) PagamentoPropina::query()->where('pagamento_id', $pagamento->id)->sum('valor');
        $multas = (int) PagamentoMulta::query()->where('pagamento_id', $pagamento->id)->sum('valor');
        $credito = $pagamento->credito?->valor->unidadesMenores() ?? 0;

        $this->assertSame($pagamento->valor->unidadesMenores(), $distribuicoes + $multas + $credito);
        $this->assertSame(2_500_000, $distribuicoes);
        $this->assertSame(25_000, $multas);
        $this->assertPagamentosCoerentes();
    }

    public function test_distribuicao_explicita_acima_de_capital_mais_multa_e_rejeitada(): void
    {
        $this->regras(parcial: true);
        [$matricula, $propina] = $this->propinaComMulta();

        $this->assertRecusado('distribuicao.0.valor', fn () => $this->previa($matricula, 2_526_000, [[$propina, 2_526_000]]));
    }

    public function test_sem_parcial_exige_capital_e_multa_em_conjunto(): void
    {
        $this->regras(parcial: false);
        [$matricula, $propina] = $this->propinaComMulta();

        $this->assertRecusado('distribuicao.0.valor', fn () => $this->previa($matricula, 2_500_000, [[$propina, 2_500_000]]));
    }

    public function test_credito_aplicado_a_multa_e_anulado_recalcula_a_multa(): void
    {
        $this->regras(parcial: true);
        ['plano' => $plano, 'matricula' => $matricula] = $this->cenarioPropinas();
        $propina = $this->propina($matricula, $plano);
        $this->pagar($matricula, 2_500_000, [[$propina, 2_500_000]]); // capital pago
        $multa = $this->multa($propina, 25_000);
        $this->creditoDirecto($this->pagamentoDirecto($matricula, 500_000), 500_000);

        $utilizacoes = app(AplicarCreditoAction::class)->executar($matricula->fresh(), $this->pedidoCredito(25_000, [[$propina, 25_000]]));

        $this->assertCount(1, $utilizacoes);
        $this->assertNotNull($utilizacoes[0]->propina_multa_id, 'O crédito liquidou a multa, não a propina.');
        $this->assertNull($utilizacoes[0]->propina_id);
        $this->assertSame(25_000, $multa->fresh()->valor_pago->unidadesMenores());

        $this->anularUtilizacao($utilizacoes[0]);
        $this->assertSame(0, $multa->fresh()->valor_pago->unidadesMenores());
        $this->assertPagamentosCoerentes();
    }

    public function test_pagamento_da_multa_conta_como_historico_da_propina(): void
    {
        ['plano' => $plano, 'matricula' => $matricula] = $this->cenarioPropinas();
        $propina = $this->propina($matricula, $plano);
        $multa = $this->multa($propina, 25_000);
        $pagamento = $this->pagamentoDirecto($matricula);

        $historico = app(HistoricoDosPagamentos::class);
        $this->assertFalse($historico->temHistorico($propina));

        PagamentoMulta::create(['pagamento_id' => $pagamento->id, 'propina_multa_id' => $multa->id, 'valor' => 10_000]);

        $this->assertTrue($historico->temHistorico($propina->fresh()));
    }

    public function test_historico_da_propina_inclui_o_credito_sobre_a_multa(): void
    {
        $this->regras(parcial: true);
        ['plano' => $plano, 'matricula' => $matricula] = $this->cenarioPropinas();
        $propina = $this->propina($matricula, $plano);
        $this->pagar($matricula, 2_500_000, [[$propina, 2_500_000]]);
        $multa = $this->multa($propina, 25_000);
        $credito = $this->creditoDirecto($this->pagamentoDirecto($matricula, 500_000), 500_000);

        CreditoUtilizacao::create([
            'credito_id' => $credito->id,
            'propina_multa_id' => $multa->id,
            'valor' => 25_000,
            'data_utilizacao' => '2026-10-16',
        ]);

        $linhas = app(PagamentoConsultaService::class)->daPropina($propina->fresh());
        $daMulta = array_values(array_filter($linhas, fn ($l) => $l['tipo'] === 'credito' && $l['valor'] === 25_000));

        $this->assertCount(1, $daMulta, 'O histórico da propina inclui o crédito sobre a multa.');
    }

    public function test_so_sao_bloqueadas_as_paga_com_multa_pendente(): void
    {
        $this->regras(antecipado: true);
        ['plano' => $plano, 'matricula' => $matricula] = $this->cenarioPropinas();
        $set = $this->propina($matricula, $plano, 1);
        $out = $this->propina($matricula, $plano, 2);
        $nov = $this->propina($matricula, $plano, 3);
        $this->pagar($matricula, 7_500_000, [[$set, 2_500_000], [$out, 2_500_000], [$nov, 2_500_000]]);
        $multaNov = $this->multa($nov, 25_000);

        $captured = null;
        $real = app(BloqueioDePropinas::class);
        $spy = \Mockery::mock(BloqueioDePropinas::class);
        $spy->shouldReceive('bloquear')->andReturnUsing(function (array $ids) use ($real, &$captured) {
            $captured ??= $ids;

            return $real->bloquear($ids);
        });
        $this->instance(BloqueioDePropinas::class, $spy);

        $this->pagar($matricula, 25_000);

        sort($captured);
        $this->assertSame([$nov->id], $captured, 'Só a PAGA com multa pendente é bloqueada (as outras PAGA ficam de fora).');
        $this->assertSame(25_000, $multaNov->fresh()->valor_pago->unidadesMenores());
        $this->assertPagamentosCoerentes();
    }
}
