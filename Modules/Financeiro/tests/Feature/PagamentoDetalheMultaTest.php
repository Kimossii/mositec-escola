<?php

namespace Modules\Financeiro\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Financeiro\Services\PagamentoConsultaService;
use Modules\Financeiro\Tests\Concerns\ComDadosAcademicosFinanceiro;
use Modules\Financeiro\Tests\Concerns\ComMultasFinanceiro;
use Modules\Financeiro\Tests\Concerns\ComPagamentosFinanceiro;
use Modules\Financeiro\Tests\Concerns\ComPropinasFinanceiro;
use Tests\TestCase;

/**
 * Leitura do destino do dinheiro de um pagamento (spec Pagamentos §7): as distribuições por multas aparecem no
 * detalhe do pagamento, ao lado das de propinas.
 */
class PagamentoDetalheMultaTest extends TestCase
{
    use ComDadosAcademicosFinanceiro;
    use ComMultasFinanceiro;
    use ComPagamentosFinanceiro;
    use ComPropinasFinanceiro;
    use RefreshDatabase;

    public function test_pagamento_que_paga_capital_e_multa_mostra_as_duas_distribuicoes(): void
    {
        $this->regras();
        ['plano' => $plano, 'matricula' => $matricula] = $this->cenarioPropinas();
        $propina = $this->propina($matricula, $plano);
        $this->multa($propina, 25_000);

        $pagamento = $this->pagar($matricula, 2_525_000, [[$propina, 2_525_000]]);

        $detalhe = app(PagamentoConsultaService::class)->detalhe($pagamento->fresh());

        $this->assertCount(1, $detalhe['distribuicoes']);
        $this->assertSame(2_500_000, $detalhe['distribuicoes'][0]['valor']);

        $this->assertCount(1, $detalhe['multas']);
        $this->assertSame(25_000, $detalhe['multas'][0]['valor']);
        $this->assertSame((int) $propina->id, $detalhe['multas'][0]['propina_id']);
        $this->assertNotSame('', $detalhe['multas'][0]['periodo']);
    }

    public function test_pagamento_sem_multa_nao_mostra_a_seccao(): void
    {
        $this->regras();
        ['plano' => $plano, 'matricula' => $matricula] = $this->cenarioPropinas();
        $propina = $this->propina($matricula, $plano);

        $pagamento = $this->pagar($matricula, 2_500_000, [[$propina, 2_500_000]]);

        $detalhe = app(PagamentoConsultaService::class)->detalhe($pagamento->fresh());

        $this->assertSame([], $detalhe['multas']);
    }
}
