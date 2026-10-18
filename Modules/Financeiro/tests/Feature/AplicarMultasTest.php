<?php

namespace Modules\Financeiro\Tests\Feature;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Modules\Financeiro\Enums\EstadoCobranca;
use Modules\Financeiro\Enums\EstadoMulta;
use Modules\Financeiro\Enums\OrigemRevisaoMulta;
use Modules\Financeiro\Enums\TipoMulta;
use Modules\Financeiro\Models\Propina;
use Modules\Financeiro\Models\PropinaMulta;
use Modules\Financeiro\Models\PropinaMultaRevisao;
use Modules\Financeiro\Models\RegraCobranca;
use Modules\Financeiro\Services\AplicarMultasService;
use Modules\Financeiro\Support\BloqueioDePropinas;
use Modules\Financeiro\Tests\Concerns\ComDadosAcademicosFinanceiro;
use Modules\Financeiro\Tests\Concerns\ComMultasFinanceiro;
use Modules\Financeiro\Tests\Concerns\ComPagamentosFinanceiro;
use Modules\Financeiro\Tests\Concerns\ComPropinasFinanceiro;
use Tests\TestCase;

/**
 * Aplicação automática das multas (spec Propinas §15.3–15.6). Propina padrão: vencimento 10/09, tolerância 5 →
 * data_limite 15/09/2026; valor 2.500.000 (25.000,00).
 */
class AplicarMultasTest extends TestCase
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

    /** @return array{criadas: int, escaloes_subidos: int, ignoradas: int} */
    private function aplicar(): array
    {
        return app(AplicarMultasService::class)->executar();
    }

    private function umaPropina(array $atributos = []): Propina
    {
        ['plano' => $plano, 'matricula' => $matricula] = $this->cenarioPropinas();

        return $this->propina($matricula, $plano, 1, $atributos);
    }

    public function test_percentagem_calcula_sobre_a_base_congelada_e_guarda_o_snapshot(): void
    {
        $this->multaActiva([[1, TipoMulta::PERCENTAGEM, 250]]); // 2,5%
        $this->umaPropina();

        $this->assertSame(['criadas' => 1, 'escaloes_subidos' => 0, 'ignoradas' => 0], $this->aplicar());

        $multa = PropinaMulta::sole();
        $this->assertSame(62_500, $multa->valor->unidadesMenores());
        $this->assertSame(2_500_000, $multa->base_capital->unidadesMenores());
        $this->assertSame(1, $multa->escalao_ordem);
        $this->assertSame(TipoMulta::PERCENTAGEM, $multa->tipo);
        $this->assertSame(250, $multa->valor_regra);
        $this->assertSame(EstadoMulta::ACTIVA, $multa->estado);
        $this->assertFalse($multa->bloqueada_automatico);
        $this->assertNull($multa->criado_por);

        $revisao = PropinaMultaRevisao::sole();
        $this->assertSame(OrigemRevisaoMulta::AUTOMATICA, $revisao->origem);
        $this->assertSame(1, $revisao->escalao_ordem);
        $this->assertSame(0, $revisao->valor_anterior);
        $this->assertSame(62_500, $revisao->valor_novo);
        $this->assertSame('2026-09-16', $revisao->data_referencia->toDateString());
        $this->assertNull($revisao->criado_por);
    }

    public function test_valor_fixo(): void
    {
        $this->multaActiva([[1, TipoMulta::VALOR_FIXO, 1_500]]);
        $this->umaPropina();

        $this->aplicar();

        $multa = PropinaMulta::sole();
        $this->assertSame(1_500, $multa->valor->unidadesMenores());
        $this->assertSame(TipoMulta::VALOR_FIXO, $multa->tipo);
        $this->assertSame(1_500, $multa->valor_regra);
    }

    public function test_valor_k_zero_nao_cria_multa_nem_revisao(): void
    {
        $this->multaActiva([[1, TipoMulta::PERCENTAGEM, 1]]); // 0,01%
        $this->umaPropina(['valor' => 500]); // 500 × 1 / 10000 = 0

        $this->assertSame(['criadas' => 0, 'escaloes_subidos' => 0, 'ignoradas' => 1], $this->aplicar());

        $this->assertSame(0, PropinaMulta::count());
        $this->assertSame(0, PropinaMultaRevisao::count());
    }

    public function test_base_congelada_nao_e_recalculada_com_pagamento_depois_de_l1(): void
    {
        $this->regras(parcial: true);
        $this->multaActiva([[1, TipoMulta::PERCENTAGEM, 500]]); // 5%, L1 = 16/09
        ['plano' => $plano, 'matricula' => $matricula] = $this->cenarioPropinas();
        $propina = $this->propina($matricula, $plano);
        $this->pagar($matricula, 500_000, [[$propina, 500_000]], 0, '2026-09-20'); // depois de L1

        $this->aplicar();

        $multa = PropinaMulta::sole();
        $this->assertSame(2_500_000, $multa->base_capital->unidadesMenores(), 'Base congelada em L1.');
        $this->assertSame(125_000, $multa->valor->unidadesMenores());
    }

    public function test_max_nunca_baixa_mesmo_com_escalao_fixo_menor(): void
    {
        $this->multaActiva([[1, TipoMulta::VALOR_FIXO, 10_000], [30, TipoMulta::VALOR_FIXO, 5_000]]);
        $this->umaPropina();

        $this->aplicar();

        $multa = PropinaMulta::sole();
        $this->assertSame(10_000, $multa->valor->unidadesMenores(), 'max() nunca baixa.');
        $this->assertSame(2, $multa->escalao_ordem);
        $this->assertSame(5_000, $multa->valor_regra, 'Snapshot do último escalão aplicado.');
    }

    public function test_capital_pago_antes_de_l2_congela_no_escalao_anterior(): void
    {
        $this->regras();
        ['plano' => $plano, 'matricula' => $matricula] = $this->cenarioPropinas();
        $propina = $this->propina($matricula, $plano);
        $this->pagar($matricula, 2_500_000, [[$propina, 2_500_000]], 0, '2026-09-20'); // entre L1 e L2

        $this->multaActiva([[1, TipoMulta::PERCENTAGEM, 500], [30, TipoMulta::PERCENTAGEM, 1000]]); // L1=16/09, L2=15/10
        $this->aplicar();

        $multa = PropinaMulta::sole();
        $this->assertSame(125_000, $multa->valor->unidadesMenores(), 'Capital pago antes de L2: só o escalão 1.');
        $this->assertSame(1, $multa->escalao_ordem);
    }

    public function test_capital_pago_depois_de_l2_aplica_o_escalao_2(): void
    {
        Carbon::setTestNow('2026-10-21 10:00:00');
        $this->regras();
        ['plano' => $plano, 'matricula' => $matricula] = $this->cenarioPropinas();
        $propina = $this->propina($matricula, $plano);
        $this->pagar($matricula, 2_500_000, [[$propina, 2_500_000]], 0, '2026-10-20'); // depois de L2

        $this->multaActiva([[1, TipoMulta::PERCENTAGEM, 500], [30, TipoMulta::PERCENTAGEM, 1000]]);
        $this->aplicar();

        $multa = PropinaMulta::sole();
        $this->assertSame(250_000, $multa->valor->unidadesMenores());
        $this->assertSame(2, $multa->escalao_ordem);
    }

    public function test_catch_up_aplica_todos_os_escaloes_ja_vencidos(): void
    {
        Carbon::setTestNow('2027-01-01 10:00:00');
        $this->multaActiva([[1, TipoMulta::PERCENTAGEM, 100], [30, TipoMulta::PERCENTAGEM, 200], [60, TipoMulta::PERCENTAGEM, 300]]);
        $this->umaPropina();

        $this->aplicar();

        $multa = PropinaMulta::sole();
        $this->assertSame(75_000, $multa->valor->unidadesMenores(), 'max(1%, 2%, 3%) sobre 2.500.000.');
        $this->assertSame(3, $multa->escalao_ordem);
        $this->assertSame(3, PropinaMultaRevisao::count(), 'Uma revisão por escalão aplicado.');
    }

    public function test_idempotente_ao_correr_duas_vezes(): void
    {
        $this->multaActiva([[1, TipoMulta::PERCENTAGEM, 250], [30, TipoMulta::PERCENTAGEM, 500]]);
        $this->umaPropina();

        $this->assertSame(['criadas' => 1, 'escaloes_subidos' => 0, 'ignoradas' => 0], $this->aplicar());
        $this->assertSame(['criadas' => 0, 'escaloes_subidos' => 0, 'ignoradas' => 0], $this->aplicar());

        $this->assertSame(1, PropinaMulta::count());
        $this->assertSame(2, PropinaMultaRevisao::count(), 'Revisões dos 2 escalões, sem duplicar.');
    }

    public function test_paga_liquidada_antes_de_l1_nao_e_avaliada_nem_abre_transacao(): void
    {
        $this->regras();
        ['plano' => $plano, 'matricula' => $matricula] = $this->cenarioPropinas();
        $propina = $this->propina($matricula, $plano);
        $this->pagar($matricula, 2_500_000, [[$propina, 2_500_000]], 0, '2026-09-10'); // pago antes de L1

        $this->multaActiva([[1, TipoMulta::PERCENTAGEM, 500]]); // L1 = 16/09
        $spy = \Mockery::mock(BloqueioDePropinas::class);
        $spy->shouldReceive('bloquear')->never();
        $this->instance(BloqueioDePropinas::class, $spy);

        $this->assertSame(['criadas' => 0, 'escaloes_subidos' => 0, 'ignoradas' => 0], $this->aplicar());
        $this->assertSame(0, PropinaMulta::count());
    }

    public function test_multa_no_ultimo_escalao_nao_e_reavaliada(): void
    {
        $this->multaActiva([[1, TipoMulta::PERCENTAGEM, 250], [30, TipoMulta::PERCENTAGEM, 500]]);
        $this->umaPropina();

        $this->aplicar();
        $this->assertSame(2, PropinaMulta::sole()->escalao_ordem);

        $spy = \Mockery::mock(BloqueioDePropinas::class);
        $spy->shouldReceive('bloquear')->never();
        $this->instance(BloqueioDePropinas::class, $spy);

        $this->assertSame(['criadas' => 0, 'escaloes_subidos' => 0, 'ignoradas' => 0], $this->aplicar());
        $this->assertSame(2, PropinaMultaRevisao::count(), 'Nenhuma revisão nova.');
    }

    public function test_catch_up_apos_anulacao_de_pagamento_continua_a_funcionar(): void
    {
        $this->regras();
        ['plano' => $plano, 'matricula' => $matricula] = $this->cenarioPropinas();
        $propina = $this->propina($matricula, $plano);

        $pagamento = $this->pagar($matricula, 2_500_000, [[$propina, 2_500_000]], 0, '2026-09-10');
        $this->anularPagamento($pagamento);
        $this->assertSame(EstadoCobranca::EM_ABERTO, $propina->fresh()->estado, 'Capital reaberto (capital_liquidado_em a nulo).');

        $this->multaActiva([[1, TipoMulta::PERCENTAGEM, 500]]); // L1 = 16/09
        $this->aplicar();

        $multa = PropinaMulta::sole();
        $this->assertSame(125_000, $multa->valor->unidadesMenores());
        $this->assertSame(2_500_000, $multa->base_capital->unidadesMenores());
    }

    public function test_multa_activa_desligada_nao_faz_nada(): void
    {
        $this->multaActiva([[1, TipoMulta::PERCENTAGEM, 500]]);
        RegraCobranca::doTenant()->update(['multa_activa' => false]);
        $this->umaPropina();

        $this->assertSame(['criadas' => 0, 'escaloes_subidos' => 0, 'ignoradas' => 0], $this->aplicar());
        $this->assertSame(0, PropinaMulta::count());
    }

    public function test_regras_alteradas_nao_mexem_na_multa_existente(): void
    {
        $this->multaActiva([[1, TipoMulta::PERCENTAGEM, 500]]); // 5%
        $this->umaPropina();
        $this->aplicar();

        $this->multaActiva([[1, TipoMulta::PERCENTAGEM, 1000]]); // regra muda para 10%
        $this->aplicar();

        $multa = PropinaMulta::sole();
        $this->assertSame(125_000, $multa->valor->unidadesMenores(), 'Multa existente não se recalcula.');
        $this->assertSame(500, $multa->valor_regra, 'Snapshot do escalão original mantido.');
    }

    public function test_multa_bloqueada_automaticamente_nao_sobe(): void
    {
        $this->multaActiva([[1, TipoMulta::PERCENTAGEM, 500], [90, TipoMulta::PERCENTAGEM, 1000]]); // L2 = 14/12
        $this->umaPropina();
        $this->aplicar();

        $multa = PropinaMulta::sole();
        $this->assertSame(1, $multa->escalao_ordem);
        $this->assertSame(125_000, $multa->valor->unidadesMenores());

        $multa->forceFill(['bloqueada_automatico' => true])->save(); // isenção/redução manual (Grupo 5)

        Carbon::setTestNow('2026-12-15 10:00:00');
        $this->aplicar();

        $multa->refresh();
        $this->assertSame(1, $multa->escalao_ordem);
        $this->assertSame(125_000, $multa->valor->unidadesMenores(), 'O comando deixa de alterar a multa bloqueada.');
    }

    public function test_isolamento_de_tenant(): void
    {
        $this->multaActiva([[1, TipoMulta::PERCENTAGEM, 500]]);
        $this->umaPropina();

        $outro = $this->criarTenant('MOSI-000002', 'Escola B', 'b.localhost');
        $this->noTenant($outro, function () {
            $this->multaActiva([[1, TipoMulta::PERCENTAGEM, 500]]);
            $this->umaPropina();
        });

        $this->aplicar();

        $this->assertSame(1, PropinaMulta::count(), 'Só a propina do tenant corrente recebe multa.');
        $this->assertSame($this->tenant->id, PropinaMulta::sole()->tenant_id);
    }

    public function test_propina_cancelada_ou_anulada_nao_recebe_multa(): void
    {
        $this->multaActiva([[1, TipoMulta::PERCENTAGEM, 500]]);
        ['plano' => $plano, 'matricula' => $matricula] = $this->cenarioPropinas();
        $this->comEstado($this->propina($matricula, $plano, 1), \Modules\Financeiro\Enums\EstadoCobranca::CANCELADA);
        $this->comEstado($this->propina($matricula, $plano, 2), \Modules\Financeiro\Enums\EstadoCobranca::ANULADA);

        $this->aplicar();

        $this->assertSame(0, PropinaMulta::count());
    }

    public function test_paga_liquidada_exactamente_em_l1_recebe_multa(): void
    {
        $this->regras();
        ['plano' => $plano, 'matricula' => $matricula] = $this->cenarioPropinas();
        $propina = $this->propina($matricula, $plano);
        $this->pagar($matricula, 2_500_000, [[$propina, 2_500_000]], 0, '2026-09-16'); // exactamente em L1

        $this->multaActiva([[1, TipoMulta::PERCENTAGEM, 500]]); // L1 = 16/09
        $this->assertSame(['criadas' => 1, 'escaloes_subidos' => 0, 'ignoradas' => 0], $this->aplicar());

        $this->assertSame(125_000, PropinaMulta::sole()->valor->unidadesMenores());
    }

    public function test_paga_liquidada_um_dia_antes_de_l1_nao_recebe_multa(): void
    {
        $this->regras();
        ['plano' => $plano, 'matricula' => $matricula] = $this->cenarioPropinas();
        $propina = $this->propina($matricula, $plano);
        $this->pagar($matricula, 2_500_000, [[$propina, 2_500_000]], 0, '2026-09-15'); // um dia antes de L1

        $this->multaActiva([[1, TipoMulta::PERCENTAGEM, 500]]); // L1 = 16/09
        $this->assertSame(['criadas' => 0, 'escaloes_subidos' => 0, 'ignoradas' => 0], $this->aplicar());

        $this->assertSame(0, PropinaMulta::count());
    }
}
