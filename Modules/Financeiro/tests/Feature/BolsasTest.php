<?php

namespace Modules\Financeiro\Tests\Feature;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Modules\Financeiro\Actions\AplicarBolsaAction;
use Modules\Financeiro\Actions\GuardarBolsaAction;
use Modules\Financeiro\Actions\TerminarBolsaAction;
use Modules\Financeiro\DTO\PedidoGeracaoPropinasDTO;
use Modules\Financeiro\Enums\AmbitoGeracaoPropinas;
use Modules\Financeiro\Enums\EstadoBolsa;
use Modules\Financeiro\Enums\OrigemGeracaoPropina;
use Modules\Financeiro\Models\Bolsa;
use Modules\Financeiro\Models\Propina;
use Modules\Financeiro\Services\GerarPropinasService;
use Modules\Financeiro\Tests\Concerns\ComDadosAcademicosFinanceiro;
use Modules\Financeiro\Tests\Concerns\ComPagamentosFinanceiro;
use Modules\Financeiro\Tests\Concerns\ComPropinasFinanceiro;
use Modules\Financeiro\Tests\Concerns\ComUtilizadoresFinanceiro;
use Modules\Permissao\Database\Seeders\PermissaoDatabaseSeeder;
use Tests\TestCase;

class BolsasTest extends TestCase
{
    use ComDadosAcademicosFinanceiro;
    use ComPagamentosFinanceiro;
    use ComPropinasFinanceiro;
    use ComUtilizadoresFinanceiro;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissaoDatabaseSeeder::class);
        Carbon::setTestNow(CarbonImmutable::parse('2026-10-16 10:00:00', 'UTC'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function bolsa($matricula, string $percentagem = '50', string $de = '2026-09-01', string $ate = '2027-06-30'): Bolsa
    {
        return app(GuardarBolsaAction::class)->executar([
            'matricula_id' => $matricula->id, 'nome' => 'Bolsa de mérito', 'percentagem' => $percentagem,
            'data_inicio' => $de, 'data_fim' => $ate, 'motivo' => 'Melhor aluno da turma',
        ]);
    }

    private function gerar($ano, $matricula)
    {
        return app(GerarPropinasService::class)->gerar(new PedidoGeracaoPropinasDTO($ano->id, AmbitoGeracaoPropinas::MATRICULA, OrigemGeracaoPropina::MANUAL, matricula_numero: $matricula->numero_registo_matricula));
    }

    public function test_propinas_geradas_dentro_da_bolsa_nascem_com_desconto(): void
    {
        ['ano' => $ano, 'matricula' => $matricula] = $this->cenarioPropinas();
        $bolsa = $this->bolsa($matricula, '50', '2026-09-01', '2026-12-31');

        $this->gerar($ano, $matricula);

        $set = Propina::query()->where('periodo_inicio', '2026-09-01')->firstOrFail();
        $this->assertSame(1_250_000, $set->valor->unidadesMenores());
        $this->assertSame(1_250_000, $set->valor_bolsa);
        $this->assertSame($bolsa->id, (int) $set->bolsa_id);
        $fev = Propina::query()->where('periodo_inicio', '2027-02-01')->firstOrFail();
        $this->assertSame(2_500_000, $fev->valor->unidadesMenores(), 'Fora da vigência, valor inteiro.');
        $this->assertNull($fev->bolsa_id);
    }

    public function test_bolsa_de_100_nao_gera_propina(): void
    {
        ['ano' => $ano, 'matricula' => $matricula] = $this->cenarioPropinas();
        $this->bolsa($matricula, '100', '2026-09-01', '2026-10-31');

        $this->gerar($ano, $matricula);

        $this->assertSame(0, Propina::query()->whereIn('periodo_inicio', ['2026-09-01', '2026-10-01'])->count());
        $this->assertSame(1, Propina::query()->where('periodo_inicio', '2026-11-01')->count());
    }

    public function test_bolsas_sobrepostas_e_fora_do_ano_sao_recusadas(): void
    {
        ['matricula' => $matricula] = $this->cenarioPropinas();
        $this->bolsa($matricula);

        foreach ([fn () => $this->bolsa($matricula, '10'), fn () => $this->bolsa($matricula, '10', '2025-01-01', '2025-12-31')] as $tentativa) {
            try {
                $tentativa();
                $this->fail('Devia recusar.');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('data_inicio', $e->errors());
            }
        }
    }

    public function test_aplica_as_propinas_em_aberto_sem_pagamentos_e_termina(): void
    {
        ['ano' => $ano, 'matricula' => $matricula] = $this->cenarioPropinas();
        $this->gerar($ano, $matricula);
        $paga = Propina::query()->where('periodo_inicio', '2026-09-01')->firstOrFail();
        $this->pagar($matricula, 2_500_000, [[$paga, 2_500_000]]);
        $bolsa = $this->bolsa($matricula, '20');

        $r = app(AplicarBolsaAction::class)->executar($bolsa);

        $this->assertSame(9, $r['aplicadas']);
        $this->assertSame(2_500_000, $paga->fresh()->valor->unidadesMenores(), 'A propina paga não muda.');
        $out = Propina::query()->where('periodo_inicio', '2026-10-01')->firstOrFail();
        $this->assertSame(2_000_000, $out->valor->unidadesMenores());
        $this->assertSame(500_000, $out->valor_bolsa);
        $this->assertSame(0, app(AplicarBolsaAction::class)->executar($bolsa)['aplicadas'], 'Idempotente.');

        $terminada = app(TerminarBolsaAction::class)->executar($bolsa, 'Aluno mudou de escola');
        $this->assertSame(EstadoBolsa::TERMINADA, $terminada->estado);
        $this->assertSame(2_000_000, $out->fresh()->valor->unidadesMenores(), 'Terminar não mexe no que já foi aplicado.');
    }
}
