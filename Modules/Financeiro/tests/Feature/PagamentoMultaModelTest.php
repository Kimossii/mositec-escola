<?php

namespace Modules\Financeiro\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Modules\Financeiro\Models\PagamentoMulta;
use Modules\Financeiro\Tests\Concerns\ComDadosAcademicosFinanceiro;
use Modules\Financeiro\Tests\Concerns\ComMultasFinanceiro;
use Modules\Financeiro\Tests\Concerns\ComPagamentosFinanceiro;
use Modules\Financeiro\Tests\Concerns\ComPropinasFinanceiro;
use Tests\TestCase;

class PagamentoMultaModelTest extends TestCase
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

        return [$matricula, $turma, $propina, $multa];
    }

    public function test_valor_tem_de_ser_positivo(): void
    {
        [$matricula, , , $multa] = $this->cenario();
        $pagamento = $this->pagamentoDirecto($matricula);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('O valor distribuído a uma multa tem de ser maior que zero.');

        PagamentoMulta::create(['pagamento_id' => $pagamento->id, 'propina_multa_id' => $multa->id, 'valor' => 0]);
    }

    public function test_so_distribui_por_multas_da_mesma_matricula(): void
    {
        [, $turma, , $multa] = $this->cenario();
        $outraMatricula = $this->matriculaActiva($turma);
        $pagamento = $this->pagamentoDirecto($outraMatricula);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Um pagamento só se distribui por multas de propinas da sua matrícula.');

        PagamentoMulta::create(['pagamento_id' => $pagamento->id, 'propina_multa_id' => $multa->id, 'valor' => 25_000]);
    }

    public function test_imutavel(): void
    {
        [$matricula, , , $multa] = $this->cenario();
        $pagamento = $this->pagamentoDirecto($matricula);
        $distribuicao = PagamentoMulta::create(['pagamento_id' => $pagamento->id, 'propina_multa_id' => $multa->id, 'valor' => 25_000]);

        try {
            $distribuicao->forceFill(['valor' => 10_000])->save();
            $this->fail('A edição devia ser recusada.');
        } catch (LogicException $e) {
            $this->assertSame('Uma distribuição de multa nunca se edita: anula-se o pagamento.', $e->getMessage());
        }

        try {
            $distribuicao->delete();
            $this->fail('A eliminação devia ser recusada.');
        } catch (LogicException $e) {
            $this->assertSame('Uma distribuição de multa nunca se elimina: anula-se o pagamento.', $e->getMessage());
        }
    }

    public function test_relacoes(): void
    {
        [$matricula, , , $multa] = $this->cenario();
        $pagamento = $this->pagamentoDirecto($matricula);
        $distribuicao = PagamentoMulta::create(['pagamento_id' => $pagamento->id, 'propina_multa_id' => $multa->id, 'valor' => 25_000]);

        $this->assertSame($pagamento->id, $distribuicao->pagamento->id);
        $this->assertSame($multa->id, $distribuicao->multa->id);
        $this->assertSame($distribuicao->id, $pagamento->multas->first()->id);
    }
}
