<?php

namespace Modules\Financeiro\Tests\Feature;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Modules\Financeiro\Actions\AlterarPrecoPlanoAction;
use Modules\Financeiro\Actions\AnularPropinaAction;
use Modules\Financeiro\DTO\MotivoDeAnulacaoDTO;
use Modules\Financeiro\Enums\EstadoCobranca;
use Modules\Financeiro\Enums\ModoAlteracaoPreco;
use Modules\Financeiro\Models\PlanoPropina;
use Modules\Financeiro\Models\Propina;
use Modules\Financeiro\Tests\Concerns\ComAlteracaoPreco;
use Modules\Financeiro\Tests\Concerns\ComDadosAcademicosFinanceiro;
use Modules\Financeiro\Tests\Concerns\ComPagamentosFinanceiro;
use Modules\Financeiro\Tests\Concerns\ComPropinasFinanceiro;
use Modules\Financeiro\Tests\Concerns\ComUtilizadoresFinanceiro;
use Modules\Matricula\Actions\AlterarEstadoMatriculaAction;
use Modules\Matricula\Enums\EstadoMatriculaEnum;
use Modules\Matricula\Models\Matricula;
use Modules\Permissao\Database\Seeders\PermissaoDatabaseSeeder;
use Tests\TestCase;

class PagamentosIntegracaoPropinasTest extends TestCase
{
    use ComAlteracaoPreco;
    use ComDadosAcademicosFinanceiro;
    use ComPagamentosFinanceiro;
    use ComPropinasFinanceiro;
    use ComUtilizadoresFinanceiro;
    use RefreshDatabase;

    private Matricula $matricula;

    private PlanoPropina $plano;

    /** @var array<int, Propina> ordem => propina (Set=1 … Jun=10) */
    private array $p = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissaoDatabaseSeeder::class);
        Carbon::setTestNow(CarbonImmutable::parse('2026-10-16 10:00:00', 'UTC'));
        ['plano' => $this->plano, 'matricula' => $this->matricula] = $this->cenarioPropinas();

        foreach (range(1, 10) as $ordem) {
            $this->p[$ordem] = $this->propina($this->matricula, $this->plano, $ordem);
        }

        $this->actingAs($this->adminEscola());
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_divida_de_matricula_cancelada_e_pagavel_e_as_canceladas_nao(): void
    {
        $this->regras(antecipado: true);
        $this->pagar($this->matricula, 2_500_000, [[$this->p[1], 2_500_000]]);

        app(AlterarEstadoMatriculaAction::class)->executar($this->matricula->fresh(), EstadoMatriculaEnum::CANCELADA, null, '2026-10-01');

        $this->assertSame(EstadoCobranca::CANCELADA, $this->p[3]->fresh()->estado, 'Novembro: futura sem pagamentos.');
        $this->assertSame(EstadoCobranca::EM_ABERTO, $this->p[2]->fresh()->estado, 'Outubro fica como dívida.');

        $this->pagar($this->matricula, 2_500_000, [[$this->p[2], 2_500_000]]);
        $this->assertSame(EstadoCobranca::PAGA, $this->p[2]->fresh()->estado);

        try {
            $this->pagar($this->matricula, 2_500_000, [[$this->p[3], 2_500_000]]);
            $this->fail('Uma cancelada não é pagável.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('distribuicao.0.propina_id', $e->errors());
        }

        $this->assertPagamentosCoerentes();
    }

    public function test_propina_futura_com_historico_nao_e_cancelada_pelo_evento_e_anula_se_a_mao(): void
    {
        $this->regras(antecipado: true);
        $this->anularPagamento($this->pagar($this->matricula, 2_500_000, [[$this->p[5], 2_500_000]])); // Janeiro: histórico

        app(AlterarEstadoMatriculaAction::class)->executar($this->matricula->fresh(), EstadoMatriculaEnum::CANCELADA, null, '2026-10-01');

        $this->assertSame(EstadoCobranca::EM_ABERTO, $this->p[5]->fresh()->estado, 'Com histórico não se cancela (F3 + Pagamentos).');
        $this->assertSame(EstadoCobranca::CANCELADA, $this->p[6]->fresh()->estado);
        $this->assertSame(EstadoCobranca::ANULADA, app(AnularPropinaAction::class)->executar(
            $this->p[5]->fresh(), new MotivoDeAnulacaoDTO('Matrícula cancelada'), auth()->id(),
        )->estado);
    }

    public function test_pagamento_real_entre_previa_e_confirmacao_faz_recusar_a_alteracao(): void
    {
        $this->regras(parcial: true, antecipado: true);
        $impressao = $this->previa($this->plano, '2027-02', 3_000_000, ModoAlteracaoPreco::APLICAR_GERADAS)->impressaoDigital;
        $this->pagar($this->matricula, 1_000_000, [[$this->p[6], 1_000_000]]);

        $this->assertRecusa('impressao_digital', 'mudaram', fn () => app(AlterarPrecoPlanoAction::class)->executar(
            $this->plano->fresh(),
            $this->pedidoAlteracao('2027-02', 3_000_000, ModoAlteracaoPreco::APLICAR_GERADAS, 'Actualização anual', $impressao),
        ));
        $this->assertSame(2_500_000, $this->p[6]->fresh()->valor->unidadesMenores());
    }

    public function test_aplicar_com_parcial_real_preserva_o_recebido(): void
    {
        $this->regras(parcial: true, antecipado: true);
        $this->pagar($this->matricula, 1_000_000, [[$this->p[6], 1_000_000]]);

        $this->alterarPreco($this->plano, '2027-02', 3_000_000, ModoAlteracaoPreco::APLICAR_GERADAS);

        $fevereiro = Propina::query()->whereKey($this->p[6]->id)->firstOrFail();
        $this->assertSame([3_000_000, 1_000_000, EstadoCobranca::PARCIALMENTE_PAGA], [$fevereiro->valor->unidadesMenores(), $fevereiro->valor_pago->unidadesMenores(), $fevereiro->estado]);
        $this->assertPagamentosCoerentes();
    }

    public function test_anular_pagamento_depois_de_descida_abaixo_do_pago(): void
    {
        $this->regras(parcial: true, antecipado: true);
        $pagamento = $this->pagar($this->matricula, 2_000_000, [[$this->p[6], 2_000_000]]);
        $this->alterarPreco($this->plano, '2027-02', 1_500_000, ModoAlteracaoPreco::APLICAR_GERADAS);
        $this->assertSame(EstadoCobranca::PAGA, $this->p[6]->fresh()->estado, 'valor = valor_pago (2.000.000), sem devolução.');

        $this->anularPagamento($pagamento);

        $fevereiro = $this->p[6]->fresh();
        $this->assertSame(EstadoCobranca::EM_ABERTO, $fevereiro->estado);
        $this->assertSame(2_000_000, $fevereiro->valor->unidadesMenores());
        $this->assertSame(2_500_000, $fevereiro->valor_original->unidadesMenores());
        $this->assertPagamentosCoerentes();
    }

    public function test_reconciliacao_global_depois_de_uma_sequencia_mista(): void
    {
        $this->regras(parcial: true, antecipado: true);
        $a = $this->pagar($this->matricula, 6_000_000, [[$this->p[1], 2_500_000], [$this->p[2], 2_500_000]]); // + 1.000.000 crédito
        $this->aplicarCredito($this->p[3], 1_000_000);                         // Nov parcial por crédito
        $b = $this->pagar($this->matricula, 2_000_000, [[$this->p[3], 1_500_000]]); // Nov paga + 500.000 crédito
        $this->anularPagamento($b);                                            // Nov volta a Parcial (só crédito); crédito de b anulado
        $this->pagar($this->matricula, 1_500_000, [[$this->p[3], 1_500_000]], data: '2026-10-10');

        $this->assertSame(EstadoCobranca::PAGA, $this->p[3]->fresh()->estado);
        $this->assertSame('2026-10-16', $this->p[3]->fresh()->capital_liquidado_em->toDateString(), 'A contribuição que fechou o saldo é a utilização de hoje (ordem por data).');
        $this->assertNotNull($a->fresh()->credito);
        $this->assertPagamentosCoerentes();
    }
}
