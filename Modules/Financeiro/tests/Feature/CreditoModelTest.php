<?php

namespace Modules\Financeiro\Tests\Feature;

use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use LogicException;
use Modules\Financeiro\Enums\EstadoCredito;
use Modules\Financeiro\Enums\EstadoUtilizacaoCredito;
use Modules\Financeiro\Models\Credito;
use Modules\Financeiro\Models\CreditoUtilizacao;
use Modules\Financeiro\Tests\Concerns\ComDadosAcademicosFinanceiro;
use Modules\Financeiro\Tests\Concerns\ComPagamentosFinanceiro;
use Modules\Financeiro\Tests\Concerns\ComPropinasFinanceiro;
use Modules\Usuario\Models\User;
use Tests\TestCase;

class CreditoModelTest extends TestCase
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

    public function test_distribuicao_so_se_cria_e_tem_de_ser_da_mesma_matricula(): void
    {
        ['plano' => $plano, 'matricula' => $matricula, 'turma' => $turma] = $this->cenarioPropinas();
        $pagamento = $this->pagamentoDirecto($matricula);
        $distribuicao = $this->distribuicaoDirecta($pagamento, $this->propina($matricula, $plano, 1), 1_000_000);

        foreach ([fn () => $distribuicao->forceFill(['valor' => 1])->save(), fn () => $distribuicao->delete()] as $operacao) {
            try {
                $operacao();
                $this->fail('A distribuição devia ser imutável.');
            } catch (LogicException) {
                $this->addToAssertionCount(1);
            }
        }

        $outra = $this->matriculaActiva($turma);
        $this->expectException(LogicException::class);
        $this->distribuicaoDirecta($pagamento, $this->propina($outra, $plano, 1), 100);
    }

    public function test_credito_nasce_disponivel_e_os_campos_de_origem_nao_mudam(): void
    {
        ['matricula' => $matricula] = $this->cenarioPropinas();
        $credito = $this->creditoDirecto($this->pagamentoDirecto($matricula), 500_000);

        $this->assertSame(EstadoCredito::DISPONIVEL, $credito->estado);
        $this->assertSame('Disponível', $credito->estado_descricao);
        $this->assertSame(500_000, $credito->saldo()->unidadesMenores());

        foreach (['valor' => 1, 'aluno_id' => $this->alunoFinanceiro('Outro')->id] as $campo => $novo) {
            try {
                $credito->fresh()->forceFill([$campo => $novo])->save();
                $this->fail("{$campo} devia ser imutável.");
            } catch (LogicException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->expectException(LogicException::class);
        $credito->delete();
    }

    public function test_credito_e_do_aluno_do_pagamento_de_origem_e_um_por_pagamento(): void
    {
        ['matricula' => $matricula] = $this->cenarioPropinas();
        $pagamento = $this->pagamentoDirecto($matricula);

        try {
            Credito::create(['aluno_id' => $this->alunoFinanceiro('Outro')->id, 'origem_pagamento_id' => $pagamento->id, 'valor' => 100]);
            $this->fail('O crédito tem de ser do aluno do pagamento.');
        } catch (LogicException) {
            $this->addToAssertionCount(1);
        }

        $this->creditoDirecto($pagamento, 100);
        $this->expectException(QueryException::class);
        $this->creditoDirecto($pagamento, 200);
    }

    public function test_coerencia_do_valor_utilizado_com_o_estado(): void
    {
        ['matricula' => $matricula] = $this->cenarioPropinas();
        $credito = $this->creditoDirecto($this->pagamentoDirecto($matricula), 500_000);

        foreach ([
            ['valor_utilizado' => 600_000, 'estado' => EstadoCredito::DISPONIVEL],
            ['valor_utilizado' => 500_000, 'estado' => EstadoCredito::DISPONIVEL],
            ['valor_utilizado' => 100_000, 'estado' => EstadoCredito::ESGOTADO],
            ['valor_utilizado' => 100_000, 'estado' => EstadoCredito::ANULADO],
        ] as $incoerente) {
            try {
                $credito->fresh()->forceFill($incoerente)->save();
                $this->fail(json_encode($incoerente));
            } catch (LogicException) {
                $this->addToAssertionCount(1);
            }
        }

        $credito->fresh()->forceFill(['valor_utilizado' => 0, 'estado' => EstadoCredito::ANULADO])->save();
        $this->expectException(LogicException::class);
        $credito->fresh()->forceFill(['estado' => EstadoCredito::DISPONIVEL])->save();
    }

    public function test_utilizacao_combinada_guarda_o_pagamento_e_o_vinculo_nao_muda(): void
    {
        ['plano' => $plano, 'matricula' => $matricula, 'turma' => $turma] = $this->cenarioPropinas();
        $credito = $this->creditoDirecto($this->pagamentoDirecto($matricula), 500_000);
        $combinado = $this->pagamentoDirecto($matricula, 1_000_000);
        $propina = $this->propina($matricula, $plano, 1);

        $isolada = $this->utilizacaoDirecta($credito, $propina, 100_000);
        $combinada = $this->utilizacaoDirecta($credito, $propina, 100_000, combinadoCom: $combinado);

        $this->assertNull($isolada->pagamento_id, 'Aplicação isolada: sem pagamento.');
        $this->assertSame((int) $combinado->id, (int) $combinada->pagamento_id);
        $this->assertSame([$combinada->id], $combinado->utilizacoesCombinadas()->pluck('id')->all());

        try {
            $combinada->fresh()->forceFill(['pagamento_id' => null])->save();
            $this->fail('O pagamento da utilização é imutável.');
        } catch (LogicException) {
            $this->addToAssertionCount(1);
        }

        $outra = $this->matriculaActiva($turma); // outro aluno: o pagamento não é do aluno do crédito
        $this->expectException(LogicException::class);
        $this->utilizacaoDirecta($credito, $propina, 100, combinadoCom: $this->pagamentoDirecto($outra));
    }

    public function test_utilizacao_so_em_propina_do_mesmo_aluno_e_so_se_anula(): void
    {
        ['plano' => $plano, 'matricula' => $matricula, 'turma' => $turma] = $this->cenarioPropinas();
        $credito = $this->creditoDirecto($this->pagamentoDirecto($matricula), 500_000);
        $utilizacao = $this->utilizacaoDirecta($credito, $this->propina($matricula, $plano, 1), 100_000);

        $this->assertSame(EstadoUtilizacaoCredito::ACTIVA, $utilizacao->estado);

        try {
            $utilizacao->forceFill(['valor' => 1])->save();
            $this->fail('O valor da utilização é imutável.');
        } catch (LogicException) {
            $this->addToAssertionCount(1);
        }

        $utilizacao->fresh()->forceFill(['estado' => EstadoUtilizacaoCredito::ANULADA, 'motivo_anulacao' => 'Engano', 'anulado_em' => now()])->save();
        $this->assertSame('Anulada', $utilizacao->fresh()->estado_descricao);

        $irmao = $this->matriculaActiva($turma); // outro aluno
        $this->expectException(LogicException::class);
        $this->utilizacaoDirecta($credito, $this->propina($irmao, $plano, 1), 100);
    }

    public function test_utilizacao_nasce_sem_dados_de_anulacao(): void
    {
        ['plano' => $plano, 'matricula' => $matricula] = $this->cenarioPropinas();
        $credito = $this->creditoDirecto($this->pagamentoDirecto($matricula), 500_000);
        $propina = $this->propina($matricula, $plano, 1);
        $utilizador = $this->utilizador();

        foreach (['anulado_em' => now(), 'motivo_anulacao' => 'Engano', 'anulado_por' => $utilizador->id] as $campo => $valor) {
            try {
                (new CreditoUtilizacao())->forceFill([
                    'credito_id' => $credito->id, 'propina_id' => $propina->id, 'valor' => 100, 'data_utilizacao' => '2026-10-16', $campo => $valor,
                ])->save();
                $this->fail("Uma utilização Activa não nasce com {$campo}.");
            } catch (LogicException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertSame(0, CreditoUtilizacao::query()->count());
    }

    public function test_autoria_e_data_de_criacao_nunca_mudam(): void
    {
        ['plano' => $plano, 'matricula' => $matricula] = $this->cenarioPropinas();
        $credito = $this->creditoDirecto($this->pagamentoDirecto($matricula), 500_000);
        $utilizacao = $this->utilizacaoDirecta($credito, $this->propina($matricula, $plano, 1), 100_000);
        $utilizador = $this->utilizador();

        foreach ([
            [$utilizacao, 'criado_por', $utilizador->id],
            [$utilizacao, 'created_at', now()->subDay()],
            [$credito, 'created_at', now()->subDay()],
        ] as [$registo, $campo, $novo]) {
            try {
                $registo->fresh()->forceFill([$campo => $novo])->save();
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
