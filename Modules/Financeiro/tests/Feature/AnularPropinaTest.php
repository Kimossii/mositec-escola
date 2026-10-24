<?php

namespace Modules\Financeiro\Tests\Feature;

use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Modules\Financeiro\Actions\AnularPropinaAction;
use Modules\Financeiro\Actions\CancelarPropinaAction;
use Modules\Financeiro\DTO\CancelarPropinaDTO;
use Modules\Financeiro\DTO\MotivoDeAnulacaoDTO;
use Modules\Financeiro\Enums\EstadoCobranca;
use Modules\Financeiro\Models\Propina;
use Modules\Financeiro\Tests\Concerns\ComDadosAcademicosFinanceiro;
use Modules\Financeiro\Tests\Concerns\ComPagamentosFinanceiro;
use Modules\Financeiro\Tests\Concerns\ComPropinasFinanceiro;
use Modules\Financeiro\Tests\Concerns\ComUtilizadoresFinanceiro;
use Modules\Matricula\Models\Matricula;
use Modules\Permissao\Database\Seeders\PermissaoDatabaseSeeder;
use Tests\TestCase;

class AnularPropinaTest extends TestCase
{
    use ComDadosAcademicosFinanceiro;
    use ComPagamentosFinanceiro;
    use ComPropinasFinanceiro;
    use ComUtilizadoresFinanceiro;
    use RefreshDatabase;

    private Matricula $matricula;

    private Propina $set;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissaoDatabaseSeeder::class);
        Carbon::setTestNow(CarbonImmutable::parse('2026-10-16 10:00:00', 'UTC'));
        ['plano' => $plano, 'matricula' => $this->matricula] = $this->cenarioPropinas();
        $this->set = $this->propina($this->matricula, $plano, 1);
        $this->actingAs($this->adminEscola());
        $this->regras();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function anular(Propina $propina, string $motivo = 'Propina emitida por engano'): Propina
    {
        return app(AnularPropinaAction::class)->executar($propina->fresh(), new MotivoDeAnulacaoDTO($motivo), auth()->id());
    }

    private function assertRecusado(string $texto, Closure $operacao): void
    {
        try {
            $operacao();
            $this->fail('Devia recusar.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString($texto, $e->errors()['propina'][0]);
        }
    }

    public function test_sem_historico_nao_se_anula_cancela_se(): void
    {
        $this->assertRecusado('cancele-a', fn () => $this->anular($this->set));
        $this->assertSame(EstadoCobranca::EM_ABERTO, $this->set->fresh()->estado);
    }

    public function test_com_pagamento_activo_nao_se_anula_nem_cancela(): void
    {
        $this->pagar($this->matricula, 2_500_000, [[$this->set, 2_500_000]]);

        $this->assertRecusado('anule primeiro os pagamentos', fn () => $this->anular($this->set));
        $this->assertRecusado('Anule primeiro os pagamentos', fn () => app(CancelarPropinaAction::class)->executar($this->set->fresh(), new CancelarPropinaDTO('Motivo qualquer'), auth()->id()));
    }

    public function test_com_todos_os_pagamentos_anulados_anula_se_e_ja_nao_se_cancela(): void
    {
        $pagamento = $this->pagar($this->matricula, 2_500_000, [[$this->set, 2_500_000]]);
        $this->anularPagamento($pagamento);

        $this->assertRecusado('não se cancela, anula-se', fn () => app(CancelarPropinaAction::class)->executar($this->set->fresh(), new CancelarPropinaDTO('Motivo qualquer'), auth()->id()));

        $anulada = $this->anular($this->set, '  Bolsa integral atribuída  ');

        $this->assertSame(EstadoCobranca::ANULADA, $anulada->estado);
        $this->assertSame('Bolsa integral atribuída', $anulada->motivo_anulacao);
        $this->assertSame(auth()->id(), (int) $anulada->anulado_por);
        $this->assertNotNull($anulada->anulado_em);
        $this->assertSame(0, $anulada->valor_pago->unidadesMenores());
        $this->assertPagamentosCoerentes();
    }

    public function test_anulada_e_terminal_e_nao_e_pagavel(): void
    {
        $this->anularPagamento($this->pagar($this->matricula, 2_500_000, [[$this->set, 2_500_000]]));
        $this->anular($this->set);

        $this->assertRecusado('já está anulada', fn () => $this->anular($this->set));

        try {
            $this->pagar($this->matricula, 2_500_000, [[$this->set, 2_500_000]]);
            $this->fail('Uma anulada não recebe pagamentos.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('distribuicao.0.propina_id', $e->errors());
        }
    }

    public function test_so_utilizacao_de_credito_anulada_tambem_permite_anular(): void
    {
        $this->regras(parcial: true);
        $out = $this->propina($this->matricula, $this->set->plano, 2);
        $this->pagar($this->matricula, 3_000_000, [[$this->set, 2_500_000]]); // 500.000 de crédito
        $utilizacao = $this->aplicarCredito($out, 500_000);
        $this->anularUtilizacao($utilizacao);

        $this->assertSame(EstadoCobranca::ANULADA, $this->anular($out)->estado);
    }
}
