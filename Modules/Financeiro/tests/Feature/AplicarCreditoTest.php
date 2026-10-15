<?php

namespace Modules\Financeiro\Tests\Feature;

use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Modules\Financeiro\Actions\AplicarCreditoAction;
use Modules\Financeiro\Enums\EstadoCobranca;
use Modules\Financeiro\Enums\EstadoCredito;
use Modules\Financeiro\Models\CreditoUtilizacao;
use Modules\Financeiro\Models\Pagamento;
use Modules\Financeiro\Models\Propina;
use Modules\Financeiro\Tests\Concerns\ComDadosAcademicosFinanceiro;
use Modules\Financeiro\Tests\Concerns\ComPagamentosFinanceiro;
use Modules\Financeiro\Tests\Concerns\ComPropinasFinanceiro;
use Modules\Matricula\Enums\EstadoMatriculaEnum;
use Modules\Matricula\Models\Matricula;
use Tests\TestCase;

class AplicarCreditoTest extends TestCase
{
    use ComDadosAcademicosFinanceiro;
    use ComPagamentosFinanceiro;
    use ComPropinasFinanceiro;
    use RefreshDatabase;

    private Matricula $matricula;

    /** @var array<string, Propina> */
    private array $p;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(CarbonImmutable::parse('2026-10-16 10:00:00', 'UTC'));
        ['plano' => $plano, 'matricula' => $this->matricula] = $this->cenarioPropinas();
        $this->p = ['set' => $this->propina($this->matricula, $plano, 1), 'out' => $this->propina($this->matricula, $plano, 2)];
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function aplicar(Matricula $matricula, int $credito, ?array $linhas = null): array
    {
        return app(AplicarCreditoAction::class)->executar($matricula->fresh(), $this->pedidoCredito($credito, $linhas));
    }

    private function assertRecusado(string $campo, Closure $operacao): void
    {
        $antes = CreditoUtilizacao::query()->count();

        try {
            $operacao();
            $this->fail("Devia recusar em {$campo}.");
        } catch (ValidationException $e) {
            $this->assertArrayHasKey($campo, $e->errors(), json_encode($e->errors()));
        }

        $this->assertSame($antes, CreditoUtilizacao::query()->count());
    }

    /** Renovação: matrícula do MESMO aluno no ano seguinte, com a 1.ª propina (Set/2027). */
    private function matriculaDoAnoSeguinte(): array
    {
        $ano = $this->anoLectivo('2027/2028', '2027-09-01', '2028-07-31');
        $turma = $this->turma($ano, $this->nivel('N' . uniqid()));
        $plano = $this->plano($ano, 'Propina Mensal 2027');
        $matricula = Matricula::create([
            'aluno_id' => $this->matricula->aluno_id,
            'turma_id' => $turma->id,
            'ano_lectivo_id' => $ano->id,
            'numero_registo_matricula' => 'MR-' . uniqid(),
            'data_matricula' => '2027-09-01',
            'estado' => EstadoMatriculaEnum::ACTIVA->value,
        ]);

        return [$matricula, $this->propina($matricula, $plano, 1)];
    }

    public function test_aplica_o_credito_sem_criar_pagamento_nem_consumir_numero(): void
    {
        $this->regras();
        $this->pagar($this->matricula, 3_000_000, [[$this->p['set'], 2_500_000]]); // 500.000 de crédito
        $this->regras(parcial: true);

        $utilizacoes = $this->aplicar($this->matricula, 500_000);

        $this->assertCount(1, $utilizacoes);
        $this->assertSame([$this->p['out']->id, 500_000, '2026-10-16'], [(int) $utilizacoes[0]->propina_id, $utilizacoes[0]->valor->unidadesMenores(), $utilizacoes[0]->data_utilizacao->toDateString()]);
        $this->assertNull($utilizacoes[0]->pagamento_id, 'O8: aplicação isolada, sem pagamento do mesmo acto.');
        $this->assertSame(1, Pagamento::query()->count());
        $this->assertSame(EstadoCobranca::PARCIALMENTE_PAGA, $this->p['out']->fresh()->estado);
        $this->assertSame(EstadoCredito::ESGOTADO, Pagamento::query()->sole()->credito->estado);
        $this->assertSame('2026-0002', $this->pagar($this->matricula, 2_000_000, [[$this->p['out'], 2_000_000]])->numero);
        $this->assertPagamentosCoerentes();
    }

    public function test_credito_do_aluno_serve_numa_matricula_de_outro_ano(): void
    {
        $this->regras();
        $this->pagar($this->matricula, 3_000_000, [[$this->p['set'], 2_500_000]]); // 500.000
        $this->pagar($this->matricula, 4_500_000, [[$this->p['out'], 2_500_000]]); // 2.000.000
        [$nova, $setembro2027] = $this->matriculaDoAnoSeguinte();
        $this->regras(antecipado: true);

        $utilizacoes = $this->aplicar($nova, 2_500_000, [[$setembro2027, 2_500_000]]);

        $this->assertCount(2, $utilizacoes, 'O crédito mais antigo primeiro, depois o seguinte.');
        $this->assertSame(EstadoCobranca::PAGA, $setembro2027->fresh()->estado);
        $this->assertPagamentosCoerentes();
    }

    public function test_credito_de_outro_aluno_nao_se_aplica(): void
    {
        $this->regras();
        $this->pagar($this->matricula, 3_000_000, [[$this->p['set'], 2_500_000]]);
        $outra = $this->matriculaActiva($this->p['set']->matricula->turma);
        $propinaDaOutra = $this->propina($outra, $this->p['set']->plano, 1);
        $this->regras(parcial: true); // sem isto a linha parcial seria recusada antes (distribuicao.0.valor)

        $this->assertRecusado('valor_credito', fn () => $this->aplicar($outra, 500_000, [[$propinaDaOutra, 500_000]]));
    }

    public function test_regras_de_parcial_valem_tambem_para_o_credito(): void
    {
        $this->regras();
        $this->pagar($this->matricula, 3_000_000, [[$this->p['set'], 2_500_000]]);

        $this->assertRecusado('distribuicao.0.valor', fn () => $this->aplicar($this->matricula, 500_000, [[$this->p['out'], 500_000]]));
        $this->assertRecusado('valor_credito', fn () => $this->aplicar($this->matricula, 500_000));
    }

    public function test_valor_zero_e_recusado_no_dto(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->pedidoCredito(0);
    }
}
