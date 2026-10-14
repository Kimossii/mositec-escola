<?php

namespace Modules\Financeiro\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Modules\Financeiro\Support\HistoricosDePagamentoDaPropina;
use Modules\Financeiro\Tests\Concerns\ComDadosAcademicosFinanceiro;
use Modules\Financeiro\Tests\Concerns\ComPropinasFinanceiro;
use stdClass;
use Tests\TestCase;

class HistoricosDePagamentoDaPropinaTest extends TestCase
{
    use ComDadosAcademicosFinanceiro;
    use ComPropinasFinanceiro;
    use RefreshDatabase;

    public function test_sem_fontes_registadas_nenhuma_propina_tem_historico(): void
    {
        ['plano' => $plano, 'matricula' => $matricula] = $this->cenarioPropinas();

        $this->assertFalse(app(HistoricosDePagamentoDaPropina::class)->temHistorico($this->propina($matricula, $plano)));
    }

    public function test_basta_uma_fonte_dizer_que_sim_e_so_para_a_propina_marcada(): void
    {
        ['plano' => $plano, 'matricula' => $matricula] = $this->cenarioPropinas();
        $marcada = $this->propina($matricula, $plano, 1);
        $outra = $this->propina($matricula, $plano, 2);
        $this->historicoPagamentoFalso('historico.a');
        $this->historicoPagamentoFalso('historico.b')->marcar($marcada);

        $historicos = app(HistoricosDePagamentoDaPropina::class);

        $this->assertTrue($historicos->temHistorico($marcada));
        $this->assertFalse($historicos->temHistorico($outra));
    }

    public function test_um_servico_etiquetado_que_nao_implementa_o_contrato_e_um_erro_de_configuracao(): void
    {
        ['plano' => $plano, 'matricula' => $matricula] = $this->cenarioPropinas();
        $this->app->instance('historico.invalido', new stdClass());
        $this->app->tag(['historico.invalido'], HistoricosDePagamentoDaPropina::ETIQUETA);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('tem de implementar');

        app(HistoricosDePagamentoDaPropina::class)->temHistorico($this->propina($matricula, $plano));
    }
}
