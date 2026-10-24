<?php

namespace Modules\Financeiro\Tests\Feature;

use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use LogicException;
use Modules\Financeiro\Enums\EstadoPagamento;
use Modules\Financeiro\Models\Pagamento;
use Modules\Financeiro\Tests\Concerns\ComDadosAcademicosFinanceiro;
use Modules\Financeiro\Tests\Concerns\ComPagamentosFinanceiro;
use Modules\Financeiro\Tests\Concerns\ComPropinasFinanceiro;
use Modules\Usuario\Models\User;
use Tests\TestCase;

class PagamentoModelTest extends TestCase
{
    use ComDadosAcademicosFinanceiro;
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

    public function test_nasce_confirmado_com_descricao_e_snapshot(): void
    {
        ['matricula' => $matricula] = $this->cenarioPropinas();

        $pagamento = $this->pagamentoDirecto($matricula, 2_500_000, ['referencia' => 'TRF-1']);

        $this->assertSame(EstadoPagamento::CONFIRMADO, $pagamento->estado);
        $this->assertSame('Confirmado', $pagamento->estado_descricao);
        $this->assertSame(2_500_000, $pagamento->valor->unidadesMenores());
        $this->assertSame('2026-10-16', $pagamento->data_pagamento->toDateString());
        $this->assertSame((int) $matricula->aluno_id, $pagamento->aluno_id);
        $this->assertTrue($pagamento->estaConfirmado());
    }

    public function test_campos_financeiros_nunca_mudam(): void
    {
        ['matricula' => $matricula] = $this->cenarioPropinas();
        $pagamento = $this->pagamentoDirecto($matricula);

        foreach ([
            'valor' => 1, 'data_pagamento' => '2026-10-01', 'metodo_pagamento_id' => $this->metodoPagamento('TPA')->id,
            'referencia' => 'X', 'observacao' => 'Y', 'numero' => '2026-9999', 'moeda' => 'USD',
        ] as $campo => $novo) {
            try {
                $pagamento->fresh()->forceFill([$campo => $novo])->save();
                $this->fail("{$campo} devia ser imutável.");
            } catch (LogicException $e) {
                $this->assertStringContainsString('nunca se edita', $e->getMessage(), $campo);
            }
        }
    }

    public function test_so_se_anula_uma_vez_e_com_motivo_e_instante(): void
    {
        ['matricula' => $matricula] = $this->cenarioPropinas();
        $pagamento = $this->pagamentoDirecto($matricula);

        try {
            $pagamento->forceFill(['estado' => EstadoPagamento::ANULADO])->save();
            $this->fail('Anular sem motivo nem instante devia lançar.');
        } catch (LogicException) {
            $this->addToAssertionCount(1);
        }

        $pagamento = $pagamento->fresh();
        $pagamento->forceFill(['estado' => EstadoPagamento::ANULADO, 'motivo_anulacao' => 'Valor errado', 'anulado_em' => now()])->save();
        $this->assertSame('Anulado', $pagamento->fresh()->estado_descricao);

        $this->expectException(LogicException::class);
        $pagamento->fresh()->forceFill(['estado' => EstadoPagamento::CONFIRMADO, 'motivo_anulacao' => null, 'anulado_em' => null])->save();
    }

    public function test_nao_se_elimina(): void
    {
        ['matricula' => $matricula] = $this->cenarioPropinas();
        $pagamento = $this->pagamentoDirecto($matricula);

        $this->expectException(LogicException::class);
        $pagamento->delete();
    }

    public function test_valor_tem_de_ser_positivo_e_o_aluno_o_da_matricula(): void
    {
        ['matricula' => $matricula] = $this->cenarioPropinas();

        try {
            $this->pagamentoDirecto($matricula, 0);
            $this->fail('Valor 0 devia lançar.');
        } catch (LogicException) {
            $this->addToAssertionCount(1);
        }

        $outro = $this->alunoFinanceiro('Outro Aluno');
        $this->expectException(LogicException::class);
        $this->pagamentoDirecto($matricula, 100, ['aluno_id' => $outro->id]);
    }

    public function test_numero_unico_por_tenant(): void
    {
        ['matricula' => $matricula] = $this->cenarioPropinas();
        $this->pagamentoDirecto($matricula, 100, ['numero' => '2026-0001']);

        $this->expectException(QueryException::class);
        $this->pagamentoDirecto($matricula, 100, ['numero' => '2026-0001']);
    }

    public function test_referencia_unica_entre_confirmados_do_mesmo_metodo_e_livre_depois_de_anular(): void
    {
        ['matricula' => $matricula] = $this->cenarioPropinas();
        $primeiro = $this->pagamentoDirecto($matricula, 100, ['referencia' => 'TRF-77']);
        $this->pagamentoDirecto($matricula, 100, ['referencia' => 'TRF-77', 'metodo_pagamento_id' => $this->metodoPagamento('TPA')->id]);

        try {
            $this->pagamentoDirecto($matricula, 100, ['referencia' => 'TRF-77']);
            $this->fail('Mesma referência no mesmo método devia falhar.');
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }

        $primeiro->forceFill(['estado' => EstadoPagamento::ANULADO, 'motivo_anulacao' => 'Valor errado', 'anulado_em' => now()])->save();
        $this->pagamentoDirecto($matricula, 100, ['referencia' => 'TRF-77']);
        $this->assertSame(3, Pagamento::query()->count());
    }

    public function test_nasce_sem_dados_de_anulacao(): void
    {
        ['matricula' => $matricula] = $this->cenarioPropinas();

        foreach (['motivo_anulacao' => 'Engano', 'anulado_em' => now(), 'anulado_por' => $this->utilizador()->id] as $campo => $valor) {
            try {
                $this->pagamentoDirecto($matricula, 100, [$campo => $valor, 'numero' => '2026-9' . strlen($campo)]);
                $this->fail("Um pagamento Confirmado não nasce com {$campo}.");
            } catch (LogicException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_autoria_e_data_de_criacao_nunca_mudam(): void
    {
        ['matricula' => $matricula] = $this->cenarioPropinas();
        $pagamento = $this->pagamentoDirecto($matricula);
        $utilizador = $this->utilizador();

        foreach (['criado_por' => $utilizador->id, 'created_at' => now()->subDay()] as $campo => $novo) {
            try {
                $pagamento->fresh()->forceFill([$campo => $novo])->save();
                $this->fail("{$campo} devia ser imutável.");
            } catch (LogicException $e) {
                $this->assertStringContainsString('nunca mudam', $e->getMessage(), $campo);
            }
        }
    }

    private function utilizador(): User
    {
        return User::firstOrCreate(['email' => 'autoria-fin@example.com'], ['name' => 'Autoria', 'password' => 'segredo123']);
    }
}
