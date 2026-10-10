<?php

namespace Modules\Financeiro\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Financeiro\Enums\EstadoCobranca;
use Modules\Financeiro\Models\ConfiguracaoMonetaria;
use Modules\Financeiro\Models\MetodoPagamento;
use Modules\Financeiro\Services\MoedaDoTenant;
use Modules\Financeiro\Support\PropinasReferenciam;
use Modules\Financeiro\Support\ReferenciasFinanceiras;
use Modules\Financeiro\Tests\Concerns\ComDadosAcademicosFinanceiro;
use Modules\Financeiro\Tests\Concerns\ComPropinasFinanceiro;
use Tests\TestCase;

class PropinasReferenciamTest extends TestCase
{
    use ComDadosAcademicosFinanceiro;
    use ComPropinasFinanceiro;
    use RefreshDatabase;

    public function test_um_plano_so_e_referenciado_pelas_suas_propinas_em_qualquer_estado(): void
    {
        ['ano' => $ano, 'plano' => $plano, 'matricula' => $matricula] = $this->cenarioPropinas();
        $semPropinas = $this->plano($ano, 'Sem Propinas');
        $referencias = app(PropinasReferenciam::class);

        $this->assertFalse($referencias->existeReferenciaA($plano));

        $this->comEstado($this->propina($matricula, $plano), EstadoCobranca::CANCELADA);

        $this->assertTrue($referencias->existeReferenciaA($plano));
        $this->assertFalse($referencias->existeReferenciaA($semPropinas));
    }

    public function test_a_moeda_fica_referenciada_por_qualquer_propina_e_so_por_propinas(): void
    {
        ['plano' => $plano, 'matricula' => $matricula] = $this->cenarioPropinas();
        $configuracao = ConfiguracaoMonetaria::doTenant();
        $referencias = app(PropinasReferenciam::class);

        $this->assertFalse($referencias->existeReferenciaA($configuracao)); // planos são preços (FonteDePrecos), não registos

        $this->propina($matricula, $plano);

        $this->assertTrue($referencias->existeReferenciaA($configuracao));
        $this->assertFalse(app(MoedaDoTenant::class)->podeAlterar());
    }

    public function test_outras_configuracoes_nao_sao_referenciadas(): void
    {
        ['plano' => $plano, 'matricula' => $matricula] = $this->cenarioPropinas();
        $this->propina($matricula, $plano);

        $this->assertFalse(app(PropinasReferenciam::class)->existeReferenciaA(new MetodoPagamento()));
    }

    public function test_esta_registada_na_etiqueta_das_referencias_financeiras(): void
    {
        ['plano' => $plano, 'matricula' => $matricula] = $this->cenarioPropinas();
        $this->propina($matricula, $plano);

        $this->assertTrue(app(ReferenciasFinanceiras::class)->existeReferenciaA($plano));
        $this->assertTrue(app(ReferenciasFinanceiras::class)->existeReferenciaA(ConfiguracaoMonetaria::doTenant()));
    }

    public function test_propinas_de_outro_tenant_nao_contam(): void
    {
        $outro = $this->criarTenant('MOSI-000002', 'Escola B', 'b.localhost');
        $this->noTenant($outro, function () {
            ['plano' => $plano, 'matricula' => $matricula] = $this->cenarioPropinas();
            $this->propina($matricula, $plano);
        });

        $this->assertFalse(app(PropinasReferenciam::class)->existeReferenciaA(ConfiguracaoMonetaria::doTenant()));
    }
}
