<?php

namespace Modules\Financeiro\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Modules\Financeiro\Enums\EstadoCobranca;
use Modules\Financeiro\Enums\EstadoPagamento;
use Modules\Financeiro\Models\CreditoUtilizacao;
use Modules\Financeiro\Models\PagamentoMulta;
use Modules\Financeiro\Models\PagamentoPropina;
use Modules\Financeiro\Services\RecalcularMulta;
use Modules\Financeiro\Services\RecalcularPropina;
use Modules\Financeiro\Tests\Concerns\ComDadosAcademicosFinanceiro;
use Modules\Financeiro\Tests\Concerns\ComMultasFinanceiro;
use Modules\Financeiro\Tests\Concerns\ComPagamentosFinanceiro;
use Modules\Financeiro\Tests\Concerns\ComPropinasFinanceiro;
use Tests\TestCase;

/**
 * Nota: o RefreshDatabase corre cada teste dentro de uma transacção, por isso chamar o serviço directamente já
 * está "dentro de uma transacção do chamador" (mesmo precedente do RecalcularPropinaTest).
 */
class RecalcularMultaTest extends TestCase
{
    use ComDadosAcademicosFinanceiro;
    use ComMultasFinanceiro;
    use ComPagamentosFinanceiro;
    use ComPropinasFinanceiro;
    use RefreshDatabase;

    private function cenario(): array
    {
        ['plano' => $plano, 'matricula' => $matricula] = $this->cenarioPropinas();
        $propina = $this->propina($matricula, $plano);
        $multa = $this->multa($propina, 25_000);

        return [$matricula, $propina, $multa];
    }

    public function test_valor_pago_e_a_soma_das_distribuicoes_de_pagamentos_confirmados(): void
    {
        [$matricula, , $multa] = $this->cenario();
        $p1 = $this->pagamentoDirecto($matricula);
        $p2 = $this->pagamentoDirecto($matricula);

        PagamentoMulta::create(['pagamento_id' => $p1->id, 'propina_multa_id' => $multa->id, 'valor' => 10_000]);
        PagamentoMulta::create(['pagamento_id' => $p2->id, 'propina_multa_id' => $multa->id, 'valor' => 15_000]);

        app(RecalcularMulta::class)->executar($multa);

        $multa->refresh();
        $this->assertSame(25_000, $multa->valor_pago->unidadesMenores());
        $this->assertSame(0, $multa->pendente()->unidadesMenores());
    }

    public function test_pagamentos_anulados_nao_contam(): void
    {
        [$matricula, , $multa] = $this->cenario();
        $pago = $this->pagamentoDirecto($matricula);
        $anulado = $this->pagamentoDirecto($matricula);
        $anulado->forceFill(['estado' => EstadoPagamento::ANULADO, 'motivo_anulacao' => 'Erro', 'anulado_em' => now()])->save();

        PagamentoMulta::create(['pagamento_id' => $pago->id, 'propina_multa_id' => $multa->id, 'valor' => 10_000]);
        PagamentoMulta::create(['pagamento_id' => $anulado->id, 'propina_multa_id' => $multa->id, 'valor' => 15_000]);

        app(RecalcularMulta::class)->executar($multa);

        $this->assertSame(10_000, $multa->fresh()->valor_pago->unidadesMenores());
    }

    public function test_utilizacoes_de_credito_activas_na_multa_tambem_contam(): void
    {
        [$matricula, , $multa] = $this->cenario();
        $credito = $this->creditoDirecto($this->pagamentoDirecto($matricula), 100_000);
        CreditoUtilizacao::create([
            'credito_id' => $credito->id,
            'propina_multa_id' => $multa->id,
            'valor' => 10_000,
            'data_utilizacao' => '2026-10-16',
        ]);

        app(RecalcularMulta::class)->executar($multa);

        $this->assertSame(10_000, $multa->fresh()->valor_pago->unidadesMenores());
    }

    public function test_o_valor_pago_nao_pode_exceder_o_valor_da_multa(): void
    {
        [$matricula, , $multa] = $this->cenario();
        $p1 = $this->pagamentoDirecto($matricula);
        $p2 = $this->pagamentoDirecto($matricula);

        PagamentoMulta::create(['pagamento_id' => $p1->id, 'propina_multa_id' => $multa->id, 'valor' => 20_000]);
        PagamentoMulta::create(['pagamento_id' => $p2->id, 'propina_multa_id' => $multa->id, 'valor' => 20_000]);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage("O valor pago (40000) excede o valor da multa {$multa->id} (25000).");

        app(RecalcularMulta::class)->executar($multa);
    }

    public function test_recalcular_propina_delega_na_multa_na_mesma_transacao(): void
    {
        [$matricula, $propina, $multa] = $this->cenario();
        $pagamento = $this->pagamentoDirecto($matricula, 2_525_000, ['data_pagamento' => '2026-09-05']);

        PagamentoPropina::create(['pagamento_id' => $pagamento->id, 'propina_id' => $propina->id, 'valor' => 2_500_000]);
        PagamentoMulta::create(['pagamento_id' => $pagamento->id, 'propina_multa_id' => $multa->id, 'valor' => 25_000]);

        app(RecalcularPropina::class)->executar($propina);

        $propina->refresh();
        $this->assertSame(EstadoCobranca::PAGA, $propina->estado);
        $this->assertSame(2_500_000, $propina->valor_pago->unidadesMenores());
        $this->assertSame(25_000, $multa->fresh()->valor_pago->unidadesMenores());
        $this->assertSame(0, $multa->fresh()->pendente()->unidadesMenores());
    }

    public function test_fora_de_uma_transacao_lanca_antes_de_ler_a_base(): void
    {
        [, , $multa] = $this->cenario();

        // Sai da transacção do RefreshDatabase para simular uma chamada sem DB::transaction.
        \Illuminate\Support\Facades\DB::rollBack();
        $this->assertSame(0, \Illuminate\Support\Facades\DB::transactionLevel());

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Recalcular uma multa exige uma transacção aberta do chamador.');

        app(RecalcularMulta::class)->executar($multa);
    }

    public function test_recalcular_uma_multa_de_outro_tenant_lanca(): void
    {
        $outro = $this->criarTenant('MOSI-000002', 'Escola B', 'b.localhost');
        $dela = $this->noTenant($outro, function () {
            ['plano' => $plano, 'matricula' => $matricula] = $this->cenarioPropinas();

            return $this->multa($this->propina($matricula, $plano), 25_000);
        });

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('A multa a recalcular não existe no tenant corrente.');

        app(RecalcularMulta::class)->executar($dela);
    }
}
