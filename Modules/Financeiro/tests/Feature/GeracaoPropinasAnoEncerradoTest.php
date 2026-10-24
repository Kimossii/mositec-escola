<?php

namespace Modules\Financeiro\Tests\Feature;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia;
use Modules\AnoLectivo\Enums\EstadoAnoLectivo;
use Modules\AnoLectivo\Models\AnoLectivo;
use Modules\Financeiro\DTO\PedidoGeracaoPropinasDTO;
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
 * Anos lectivos encerrados nunca geram propinas (§7). O interruptor de segurança de F2 (R-F2.1) foi removido na
 * Task 16 de F3, com autorização do dono: a geração já não depende de configuração.
 */
class GeracaoPropinasAnoEncerradoTest extends TestCase
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

    private function encerrar(AnoLectivo $ano): void
    {
        $ano->forceFill(['estado' => EstadoAnoLectivo::ENCERRADO])->saveQuietly();
    }

    private function pedido(AnoLectivo $ano): array
    {
        return ['ano_lectivo_id' => $ano->id, 'ambito' => 'turma', 'turma_id' => Turma::query()->where('ano_lectivo_id', $ano->id)->value('id')];
    }

    public function test_o_interruptor_de_seguranca_ja_nao_existe(): void
    {
        $this->assertNull(config('financeiro.propinas.geracao_activa'));
        $this->assertFalse(class_exists('Modules\\Financeiro\\Exceptions\\GeracaoDePropinasDesactivada'));

        $this->actingAs($this->adminEscola())
            ->get(route('financeiro.propinas.index'))
            ->assertInertia(fn (AssertableInertia $p) => $p->missing('financeiro'));
    }

    public function test_a_regra_aceita_ligar_a_geracao_automatica(): void
    {
        $this->actingAs($this->adminEscola())
            ->put(self::REGRA, [
                'dia_vencimento' => 10, 'dias_tolerancia' => 5, 'permite_pagamento_parcial' => true,
                'permite_pagamento_antecipado' => false, 'gerar_automaticamente' => true,
                'permite_negociacao' => false, 'desconto_maximo_negociacao' => 0,
            ])
            ->assertSessionHasNoErrors();

        $this->assertTrue((bool) RegraCobranca::doTenant()->gerar_automaticamente);
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
        } catch (ValidationException $e) {
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
