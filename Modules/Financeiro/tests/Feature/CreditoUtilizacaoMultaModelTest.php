<?php

namespace Modules\Financeiro\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Modules\Financeiro\Models\CreditoUtilizacao;
use Modules\Financeiro\Tests\Concerns\ComDadosAcademicosFinanceiro;
use Modules\Financeiro\Tests\Concerns\ComMultasFinanceiro;
use Modules\Financeiro\Tests\Concerns\ComPagamentosFinanceiro;
use Modules\Financeiro\Tests\Concerns\ComPropinasFinanceiro;
use Tests\TestCase;

class CreditoUtilizacaoMultaModelTest extends TestCase
{
    use ComDadosAcademicosFinanceiro;
    use ComMultasFinanceiro;
    use ComPagamentosFinanceiro;
    use ComPropinasFinanceiro;
    use RefreshDatabase;

    private function cenario(): array
    {
        ['turma' => $turma, 'plano' => $plano, 'matricula' => $matricula] = $this->cenarioPropinas();
        $propina = $this->propina($matricula, $plano);
        $multa = $this->multa($propina, 25_000);
        $credito = $this->creditoDirecto($this->pagamentoDirecto($matricula), 100_000);

        return [$matricula, $turma, $propina, $multa, $credito];
    }

    public function test_uma_utilizacao_liquida_uma_propina_ou_uma_multa_nunca_nenhuma_nem_ambas(): void
    {
        [, , , $multa, $credito] = $this->cenario();

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Uma utilização de crédito liquida uma propina OU uma multa, nunca as duas nem nenhuma.');

        CreditoUtilizacao::create(['credito_id' => $credito->id, 'valor' => 10_000, 'data_utilizacao' => '2026-10-16']);
    }

    public function test_nao_liquida_a_propina_e_a_multa_ao_mesmo_tempo(): void
    {
        [, , $propina, $multa, $credito] = $this->cenario();

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Uma utilização de crédito liquida uma propina OU uma multa, nunca as duas nem nenhuma.');

        CreditoUtilizacao::create([
            'credito_id' => $credito->id,
            'propina_id' => $propina->id,
            'propina_multa_id' => $multa->id,
            'valor' => 10_000,
            'data_utilizacao' => '2026-10-16',
        ]);
    }

    public function test_utilizacao_em_multa_resolve_a_propina_e_a_matricula(): void
    {
        [, , , $multa, $credito] = $this->cenario();
        $utilizacao = CreditoUtilizacao::create([
            'credito_id' => $credito->id,
            'propina_multa_id' => $multa->id,
            'valor' => 10_000,
            'data_utilizacao' => '2026-10-16',
        ])->refresh();

        $this->assertSame($multa->id, $utilizacao->multa->id);
        $this->assertSame($multa->propina_id, $utilizacao->multa->propina->id);
        $this->assertNull($utilizacao->propina);
    }

    public function test_credito_de_outro_aluno_nao_liquida_a_multa(): void
    {
        [, $turma, , $multa] = $this->cenario();
        $outraMatricula = $this->matriculaActiva($turma);
        $credito = $this->creditoDirecto($this->pagamentoDirecto($outraMatricula), 100_000);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Um crédito só se usa em propinas de matrículas do mesmo aluno.');

        CreditoUtilizacao::create([
            'credito_id' => $credito->id,
            'propina_multa_id' => $multa->id,
            'valor' => 10_000,
            'data_utilizacao' => '2026-10-16',
        ]);
    }
}
