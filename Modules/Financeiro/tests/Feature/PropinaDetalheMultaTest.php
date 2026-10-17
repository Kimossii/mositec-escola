<?php

namespace Modules\Financeiro\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Financeiro\Enums\EstadoMulta;
use Modules\Financeiro\Services\PagamentoConsultaService;
use Modules\Financeiro\Services\PropinaConsultaService;
use Modules\Financeiro\Tests\Concerns\ComDadosAcademicosFinanceiro;
use Modules\Financeiro\Tests\Concerns\ComMultasFinanceiro;
use Modules\Financeiro\Tests\Concerns\ComPagamentosFinanceiro;
use Modules\Financeiro\Tests\Concerns\ComPropinasFinanceiro;
use Tests\TestCase;

/**
 * Leitura da multa por propina (spec Propinas §15.6): detalhe/lista devolvem a multa e o total em dívida; a situação
 * do pagamento devolve capital, multa e total.
 */
class PropinaDetalheMultaTest extends TestCase
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

        return [$matricula, $plano, $propina];
    }

    public function test_detalhe_devolve_a_multa_e_o_total_em_divida(): void
    {
        [$matricula, , $propina] = $this->cenario();
        $this->multa($propina, 25_000);

        $detalhe = app(PropinaConsultaService::class)->detalhe($propina->fresh());

        $this->assertSame(2_500_000, $detalhe['saldo']);
        $this->assertSame(25_000, $detalhe['multa']['valor']);
        $this->assertSame(0, $detalhe['multa']['valor_pago']);
        $this->assertSame(25_000, $detalhe['multa']['pendente']);
        $this->assertSame(EstadoMulta::ACTIVA->value, $detalhe['multa']['estado']);
        $this->assertFalse($detalhe['multa']['bloqueada_automatico']);
        $this->assertSame(2_525_000, $detalhe['total_em_divida']);
    }

    public function test_detalhe_sem_multa_devolve_multa_nula(): void
    {
        [, , $propina] = $this->cenario();

        $detalhe = app(PropinaConsultaService::class)->detalhe($propina->fresh());

        $this->assertNull($detalhe['multa']);
        $this->assertSame(2_500_000, $detalhe['total_em_divida']);
    }

    public function test_lista_devolve_a_multa_e_o_total_por_propina(): void
    {
        [$matricula, $plano, $propina] = $this->cenario();
        $this->multa($propina, 25_000);

        $pagina = app(PropinaConsultaService::class)->listar(['ano_lectivo_id' => (string) $plano->ano_lectivo_id]);
        $linha = $pagina->items()[0];

        $this->assertSame(25_000, $linha['multa']['pendente']);
        $this->assertSame(2_525_000, $linha['total_em_divida']);
    }

    public function test_situacao_devolve_capital_multa_e_total(): void
    {
        [$matricula, , $propina] = $this->cenario();
        $this->multa($propina, 25_000);

        $situacao = app(PagamentoConsultaService::class)->situacao($matricula->fresh());
        $linha = $situacao['propinas'][0];

        $this->assertSame(2_500_000, $linha['capital_em_divida']);
        $this->assertSame(25_000, $linha['multa_pendente']);
        $this->assertSame(2_525_000, $linha['total_em_divida']);
    }
}
