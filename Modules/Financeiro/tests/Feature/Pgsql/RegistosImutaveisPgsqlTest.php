<?php

namespace Modules\Financeiro\Tests\Feature\Pgsql;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Modules\Financeiro\Enums\ModoAlteracaoPreco;
use Modules\Financeiro\Models\AlteracaoPrecoPlano;
use Modules\Financeiro\Models\PlanoPropina;
use Modules\Financeiro\Models\PropinaAjuste;
use Modules\Financeiro\Support\Dinheiro;
use Modules\Financeiro\Tests\Concerns\ComDadosAcademicosFinanceiro;
use Modules\Financeiro\Tests\Concerns\ComPropinasFinanceiro;
use PHPUnit\Framework\Attributes\Group;
use Tests\PgsqlTestCase;

/** Os triggers BEFORE UPDATE OR DELETE e os CHECK de F4: nem SQL cru altera ou apaga os registos de alteração de preço. */
#[Group('pgsql')]
class RegistosImutaveisPgsqlTest extends PgsqlTestCase
{
    use ComDadosAcademicosFinanceiro;
    use ComPropinasFinanceiro;

    private function alteracao(?PlanoPropina $plano = null, int $totalAfectadas = 1): AlteracaoPrecoPlano
    {
        $plano ??= $this->cenarioPropinas()['plano'];

        return AlteracaoPrecoPlano::create([
            'ano_lectivo_id' => $plano->ano_lectivo_id,
            'plano_propina_id' => $plano->id,
            'modo' => ModoAlteracaoPreco::APLICAR_GERADAS,
            'valor_anterior' => Dinheiro::deUnidadesMenores(2_500_000),
            'valor_novo' => Dinheiro::deUnidadesMenores(3_000_000),
            'mes_efeito' => '2027-01-01',
            'motivo' => 'Actualização anual',
            'total_propinas_afectadas' => $totalAfectadas,
            'impressao_digital' => str_repeat('a', 64),
        ]);
    }

    public function test_a_base_recusa_update_e_delete_de_uma_alteracao_de_preco(): void
    {
        $id = $this->alteracao()->id;

        foreach (['update' => fn () => DB::table('alteracoes_preco_plano')->where('id', $id)->update(['motivo' => 'x']),
            'delete' => fn () => DB::table('alteracoes_preco_plano')->where('id', $id)->delete()] as $operacao) {
            try {
                DB::transaction($operacao);
                $this->fail('O trigger devia recusar a operação.');
            } catch (QueryException $e) {
                $this->assertSame('23000', $e->errorInfo[0] ?? null, $e->getMessage());
                $this->assertStringContainsString('imutável', $e->getMessage());
            }
        }
    }

    public function test_a_base_recusa_por_check_um_total_negativo_no_insert(): void
    {
        try {
            DB::transaction(fn () => $this->alteracao(totalAfectadas: -1));
            $this->fail('O CHECK devia recusar o total negativo.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('alteracoes_preco_plano_total_check', $e->getMessage());
        }
    }

    public function test_a_base_recusa_update_e_delete_de_um_ajuste(): void
    {
        ['plano' => $plano, 'matricula' => $matricula] = $this->cenarioPropinas();
        $propina = $this->propina($matricula, $plano, 5);
        $alteracao = $this->alteracao($plano);

        $ajuste = PropinaAjuste::create([
            'alteracao_preco_id' => $alteracao->id,
            'propina_id' => $propina->id,
            'valor_anterior' => Dinheiro::deUnidadesMenores(2_500_000),
            'valor_novo' => Dinheiro::deUnidadesMenores(3_000_000),
            'valor_pago_no_momento' => Dinheiro::deUnidadesMenores(0),
            'plano_anterior_id' => $plano->id,
            'plano_novo_id' => null,
            'motivo' => 'Actualização anual',
        ]);

        foreach (['update' => fn () => DB::table('propina_ajustes')->where('id', $ajuste->id)->update(['motivo' => 'x']),
            'delete' => fn () => DB::table('propina_ajustes')->where('id', $ajuste->id)->delete()] as $operacao) {
            try {
                DB::transaction($operacao);
                $this->fail('O trigger devia recusar a operação.');
            } catch (QueryException $e) {
                $this->assertSame('23000', $e->errorInfo[0] ?? null, $e->getMessage());
                $this->assertStringContainsString('imutável', $e->getMessage());
            }
        }
    }
}
