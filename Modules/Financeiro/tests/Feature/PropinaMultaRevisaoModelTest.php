<?php

namespace Modules\Financeiro\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Modules\Financeiro\Enums\OrigemRevisaoMulta;
use Modules\Financeiro\Models\PropinaMultaRevisao;
use Modules\Financeiro\Tests\Concerns\ComDadosAcademicosFinanceiro;
use Modules\Financeiro\Tests\Concerns\ComMultasFinanceiro;
use Modules\Financeiro\Tests\Concerns\ComPropinasFinanceiro;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PropinaMultaRevisaoModelTest extends TestCase
{
    use ComDadosAcademicosFinanceiro;
    use ComMultasFinanceiro;
    use ComPropinasFinanceiro;
    use RefreshDatabase;

    private function umaMulta()
    {
        ['plano' => $plano, 'matricula' => $matricula] = $this->cenarioPropinas();

        return $this->multa($this->propina($matricula, $plano), 25_000);
    }

    public function test_revisao_automatica_regista_escalao_e_data_sem_motivo_nem_autor(): void
    {
        $multa = $this->umaMulta();
        $revisao = PropinaMultaRevisao::create([
            'propina_multa_id' => $multa->id,
            'origem' => OrigemRevisaoMulta::AUTOMATICA,
            'escalao_ordem' => 2,
            'valor_anterior' => 0,
            'valor_novo' => 50_000,
            'data_referencia' => '2026-09-16',
        ])->refresh();

        $this->assertSame(OrigemRevisaoMulta::AUTOMATICA, $revisao->origem);
        $this->assertSame(2, $revisao->escalao_ordem);
        $this->assertSame('2026-09-16', $revisao->data_referencia->toDateString());
        $this->assertNull($revisao->motivo);
        $this->assertNull($revisao->criado_por);
    }

    public function test_revisao_manual_regista_motivo_sem_escalao_nem_data(): void
    {
        $multa = $this->umaMulta();
        $revisao = PropinaMultaRevisao::create([
            'propina_multa_id' => $multa->id,
            'origem' => OrigemRevisaoMulta::MANUAL,
            'valor_anterior' => 25_000,
            'valor_novo' => 10_000,
            'motivo' => 'Isento por decisão da direcção.',
        ])->refresh();

        $this->assertSame(OrigemRevisaoMulta::MANUAL, $revisao->origem);
        $this->assertNull($revisao->escalao_ordem);
        $this->assertNull($revisao->data_referencia);
        $this->assertSame('Isento por decisão da direcção.', $revisao->motivo);
    }

    #[DataProvider('invalidas')]
    public function test_revisoes_invalidas_sao_recusadas(array $atributos, string $mensagem): void
    {
        $multa = $this->umaMulta();

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage($mensagem);

        PropinaMultaRevisao::create(['propina_multa_id' => $multa->id] + $atributos);
    }

    public static function invalidas(): array
    {
        return [
            'manual sem motivo' => [
                ['origem' => OrigemRevisaoMulta::MANUAL, 'valor_anterior' => 25_000, 'valor_novo' => 10_000],
                'Uma revisão manual de multa exige o motivo.',
            ],
            'manual com escalao' => [
                ['origem' => OrigemRevisaoMulta::MANUAL, 'escalao_ordem' => 1, 'valor_anterior' => 25_000, 'valor_novo' => 10_000, 'motivo' => 'Motivo'],
                'Uma revisão manual de multa não tem escalão nem data de referência.',
            ],
            'automatica sem escalao' => [
                ['origem' => OrigemRevisaoMulta::AUTOMATICA, 'valor_anterior' => 0, 'valor_novo' => 10_000, 'data_referencia' => '2026-09-16'],
                'Uma revisão automática de multa exige o escalão e a data de referência.',
            ],
            'automatica com motivo' => [
                ['origem' => OrigemRevisaoMulta::AUTOMATICA, 'escalao_ordem' => 1, 'valor_anterior' => 0, 'valor_novo' => 10_000, 'data_referencia' => '2026-09-16', 'motivo' => 'Motivo'],
                'Uma revisão automática de multa não tem motivo nem autor.',
            ],
            'valor negativo' => [
                ['origem' => OrigemRevisaoMulta::AUTOMATICA, 'escalao_ordem' => 1, 'valor_anterior' => -1, 'valor_novo' => 10_000, 'data_referencia' => '2026-09-16'],
                'Os valores de uma revisão de multa não podem ser negativos.',
            ],
        ];
    }

    public function test_so_se_acrescenta(): void
    {
        $multa = $this->umaMulta();
        $revisao = PropinaMultaRevisao::create([
            'propina_multa_id' => $multa->id,
            'origem' => OrigemRevisaoMulta::AUTOMATICA,
            'escalao_ordem' => 1,
            'valor_anterior' => 0,
            'valor_novo' => 25_000,
            'data_referencia' => '2026-09-16',
        ]);

        try {
            $revisao->forceFill(['valor_novo' => 99])->save();
            $this->fail('A edição devia ser recusada.');
        } catch (LogicException $e) {
            $this->assertSame('As revisões de uma multa só se acrescentam: nunca se editam.', $e->getMessage());
        }

        try {
            $revisao->delete();
            $this->fail('A eliminação devia ser recusada.');
        } catch (LogicException $e) {
            $this->assertSame('As revisões de uma multa só se acrescentam: nunca se eliminam.', $e->getMessage());
        }
    }
}
