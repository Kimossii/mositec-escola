<?php

namespace Modules\Financeiro\Tests\Feature;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia;
use Modules\AnoLectivo\Enums\EstadoAnoLectivo;
use Modules\AnoLectivo\Models\AnoLectivo;
use Modules\Financeiro\DTO\PedidoGeracaoPropinasDTO;
use Modules\Financeiro\Exceptions\GeracaoDePropinasDesactivada;
use Modules\Financeiro\Models\Propina;
use Modules\Financeiro\Models\RegraCobranca;
use Modules\Financeiro\Services\GerarPropinasService;
use Modules\Financeiro\Support\Dinheiro;
use Modules\Financeiro\Tests\Concerns\ComDadosAcademicosFinanceiro;
use Modules\Financeiro\Tests\Concerns\ComPropinasFinanceiro;
use Modules\Financeiro\Tests\Concerns\ComUtilizadoresFinanceiro;
use Modules\Permissao\Database\Seeders\PermissaoDatabaseSeeder;
use Modules\Turma\Models\Turma;
use Tests\TestCase;

/**
 * R-F2.1 (interruptor `financeiro.propinas.geracao_activa`) e anos lectivos encerrados (§7).
 * A suite corre com o interruptor LIGADO (phpunit.xml); aqui desliga-se com config()->set.
 */
class GeracaoPropinasInterruptorTest extends TestCase
{
    use ComDadosAcademicosFinanceiro;
    use ComPropinasFinanceiro;
    use ComUtilizadoresFinanceiro;
    use RefreshDatabase;

    private const PREVIA = 'financeiro.propinas.geracao.pre-visualizar';

    private const GERAR = 'financeiro.propinas.geracao.store';

    private const REGRA = '/financeiro/configuracao/regras-cobranca';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissaoDatabaseSeeder::class);
        Carbon::setTestNow(CarbonImmutable::parse('2026-09-01 10:00:00', 'UTC'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function desligar(): void
    {
        config()->set('financeiro.propinas.geracao_activa', false);
    }

    private function encerrar(AnoLectivo $ano): void
    {
        $ano->forceFill(['estado' => EstadoAnoLectivo::ENCERRADO])->saveQuietly();
    }

    private function pedido(AnoLectivo $ano): array
    {
        return ['ano_lectivo_id' => $ano->id, 'ambito' => 'turma', 'turma_id' => Turma::query()->where('ano_lectivo_id', $ano->id)->value('id')];
    }

    private function payloadRegra(array $sobrepor = []): array
    {
        return array_merge([
            'dia_vencimento' => 10, 'dias_tolerancia' => 5, 'permite_pagamento_parcial' => true,
            'permite_pagamento_antecipado' => false, 'gerar_automaticamente' => false,
            'permite_negociacao' => false, 'desconto_maximo_negociacao' => 0,
        ], $sobrepor);
    }

    // --- Interruptor desligado ---

    public function test_por_omissao_o_interruptor_esta_desligado_na_configuracao_do_modulo(): void
    {
        $antes = [$_ENV['FINANCEIRO_GERACAO_PROPINAS'] ?? null, $_SERVER['FINANCEIRO_GERACAO_PROPINAS'] ?? null, getenv('FINANCEIRO_GERACAO_PROPINAS')];
        unset($_ENV['FINANCEIRO_GERACAO_PROPINAS'], $_SERVER['FINANCEIRO_GERACAO_PROPINAS']);
        putenv('FINANCEIRO_GERACAO_PROPINAS');

        try {
            $this->assertFalse((require base_path('Modules/Financeiro/config/config.php'))['propinas']['geracao_activa']);
        } finally {
            $_ENV['FINANCEIRO_GERACAO_PROPINAS'] = $antes[0];
            $_SERVER['FINANCEIRO_GERACAO_PROPINAS'] = $antes[1];
            putenv('FINANCEIRO_GERACAO_PROPINAS=' . $antes[2]);
        }
    }

    public function test_servico_recusa_gerar_com_o_interruptor_desligado(): void
    {
        ['ano' => $ano] = $this->cenarioPropinas();
        $this->desligar();

        try {
            app(GerarPropinasService::class)->gerar(PedidoGeracaoPropinasDTO::automatica($ano->id));
            $this->fail('Devia ter recusado.');
        } catch (GeracaoDePropinasDesactivada $e) {
            $this->assertStringContainsString('desactivada', $e->getMessage());
        }

        $this->assertSame(0, Propina::query()->count());
    }

    public function test_http_pre_visualizar_e_confirmar_dao_403_com_o_interruptor_desligado(): void
    {
        ['ano' => $ano] = $this->cenarioPropinas();
        $this->desligar();
        $this->actingAs($this->adminEscola());

        $this->postJson(route(self::PREVIA), $this->pedido($ano))->assertForbidden();
        $this->postJson(route(self::GERAR), $this->pedido($ano))->assertForbidden();
        $this->assertSame(0, Propina::query()->count());
    }

    public function test_comando_fica_inerte_com_o_interruptor_desligado(): void
    {
        RegraCobranca::doTenant()->update(['gerar_automaticamente' => true]);
        $this->cenarioPropinas();
        $this->desligar();

        $this->artisan('financeiro:gerar-propinas', ['--tenant' => $this->tenant->codigo])
            ->expectsOutputToContain('desactivada')
            ->assertSuccessful();

        $this->assertSame(0, Propina::query()->count());
    }

    public function test_regra_rejeita_gerar_automaticamente_true_com_o_interruptor_desligado(): void
    {
        $this->desligar();

        $this->actingAs($this->adminEscola())
            ->put(self::REGRA, $this->payloadRegra(['gerar_automaticamente' => true]))
            ->assertSessionHasErrors('gerar_automaticamente');

        $this->assertFalse((bool) RegraCobranca::doTenant()->gerar_automaticamente);
    }

    public function test_regra_aceita_false_e_valor_true_inalterado_com_o_interruptor_desligado(): void
    {
        $this->desligar();
        $this->actingAs($this->adminEscola());

        $this->put(self::REGRA, $this->payloadRegra(['gerar_automaticamente' => false]))->assertSessionHasNoErrors();

        RegraCobranca::doTenant()->update(['gerar_automaticamente' => true]);
        $this->put(self::REGRA, $this->payloadRegra(['gerar_automaticamente' => true, 'dia_vencimento' => 12]))->assertSessionHasNoErrors();
        $this->assertSame(12, RegraCobranca::doTenant()->dia_vencimento);
    }

    public function test_regra_aceita_true_com_o_interruptor_ligado(): void
    {
        $this->actingAs($this->adminEscola())
            ->put(self::REGRA, $this->payloadRegra(['gerar_automaticamente' => true]))
            ->assertSessionHasNoErrors();

        $this->assertTrue((bool) RegraCobranca::doTenant()->gerar_automaticamente);
    }

    public function test_prop_partilhada_expoe_o_estado_do_interruptor(): void
    {
        $this->actingAs($this->adminEscola());

        $this->get(route('financeiro.propinas.index'))
            ->assertInertia(fn (AssertableInertia $p) => $p->where('financeiro.geracaoPropinasActiva', true));

        $this->desligar();

        $this->get(route('financeiro.propinas.index'))
            ->assertInertia(fn (AssertableInertia $p) => $p->where('financeiro.geracaoPropinasActiva', false));
        $this->get(self::REGRA)
            ->assertInertia(fn (AssertableInertia $p) => $p->where('financeiro.geracaoPropinasActiva', false));
    }

    // --- Anos lectivos encerrados ---

    public function test_http_rejeita_ano_lectivo_encerrado_em_ano_lectivo_id(): void
    {
        ['ano' => $ano] = $this->cenarioPropinas();
        $this->encerrar($ano);
        $this->actingAs($this->adminEscola());

        $this->postJson(route(self::PREVIA), $this->pedido($ano))
            ->assertUnprocessable()
            ->assertJsonPath('errors.ano_lectivo_id.0', 'Não é possível gerar propinas para um ano lectivo encerrado.');
        $this->postJson(route(self::GERAR), $this->pedido($ano))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('ano_lectivo_id');
        $this->assertSame(0, Propina::query()->count());
    }

    public function test_http_aceita_anos_activo_e_planeado(): void
    {
        ['ano' => $ano] = $this->cenarioPropinas();
        $this->actingAs($this->adminEscola());

        $this->postJson(route(self::PREVIA), $this->pedido($ano))->assertOk();

        $ano->forceFill(['estado' => EstadoAnoLectivo::PLANEADO])->saveQuietly();
        $this->postJson(route(self::PREVIA), $this->pedido($ano))->assertOk();
    }

    public function test_servico_recusa_gerar_num_ano_encerrado(): void
    {
        ['ano' => $ano] = $this->cenarioPropinas();
        $this->encerrar($ano);

        try {
            app(GerarPropinasService::class)->gerar(PedidoGeracaoPropinasDTO::automatica($ano->id));
            $this->fail('Devia ter recusado.');
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->assertArrayHasKey('ano_lectivo_id', $e->errors());
        }

        $this->assertSame(0, Propina::query()->count());
    }

    public function test_comando_ignora_matriculas_de_ano_lectivo_encerrado(): void
    {
        RegraCobranca::doTenant()->update(['gerar_automaticamente' => true]);
        ['matricula' => $actual] = $this->cenarioPropinas();
        $anterior = $this->anoLectivo('2025/2026', '2025-09-01', '2026-07-31');
        $this->plano($anterior, 'Propina 2025/2026', ['valor' => Dinheiro::deUnidadesMenores(2_000_000)]);
        $antiga = $this->matriculaActiva($this->turma($anterior, $this->nivel('NA')), '2025-09-01');
        $this->encerrar($anterior);

        $this->artisan('financeiro:gerar-propinas', ['--tenant' => $this->tenant->codigo])->assertSuccessful();

        $this->assertSame(10, Propina::query()->where('matricula_id', $actual->id)->count());
        $this->assertSame(0, Propina::query()->where('matricula_id', $antiga->id)->count());
    }
}
