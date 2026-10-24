<?php

namespace Modules\Financeiro\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use LogicException;
use Modules\Financeiro\Enums\ModoAlteracaoPreco;
use Modules\Financeiro\Models\AlteracaoPrecoPlano;
use Modules\Financeiro\Models\PlanoPropina;
use Modules\Financeiro\Models\Propina;
use Modules\Financeiro\Models\PropinaAjuste;
use Modules\Financeiro\Support\Dinheiro;
use Modules\Financeiro\Tests\Concerns\ComDadosAcademicosFinanceiro;
use Modules\Financeiro\Tests\Concerns\ComPropinasFinanceiro;
use Modules\Financeiro\Tests\Concerns\ComUtilizadoresFinanceiro;
use Modules\Permissao\Database\Seeders\PermissaoDatabaseSeeder;
use PHPUnit\Framework\AssertionFailedError;
use Tests\TestCase;

class AlteracaoPrecoRegistosTest extends TestCase
{
    use ComDadosAcademicosFinanceiro;
    use ComPropinasFinanceiro;
    use ComUtilizadoresFinanceiro;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissaoDatabaseSeeder::class);
    }

    /** @return array{plano: PlanoPropina, propina: Propina, alteracao: AlteracaoPrecoPlano} */
    private function registos(): array
    {
        ['plano' => $plano, 'matricula' => $matricula] = $this->cenarioPropinas();
        $propina = $this->propina($matricula, $plano, 5);

        $alteracao = AlteracaoPrecoPlano::create([
            'ano_lectivo_id' => $plano->ano_lectivo_id,
            'plano_propina_id' => $plano->id,
            'plano_novo_id' => null,
            'modo' => ModoAlteracaoPreco::APLICAR_GERADAS,
            'valor_anterior' => Dinheiro::deUnidadesMenores(2_500_000),
            'valor_novo' => Dinheiro::deUnidadesMenores(3_000_000),
            'mes_efeito' => '2027-01-01',
            'motivo' => 'Actualização anual',
            'total_propinas_afectadas' => 1,
            'impressao_digital' => str_repeat('a', 64),
        ]);

        return ['plano' => $plano, 'propina' => $propina, 'alteracao' => $alteracao];
    }

    public function test_a_alteracao_regista_modo_descricao_autor_e_snapshot(): void
    {
        $admin = $this->adminEscola();
        $this->actingAs($admin);

        ['alteracao' => $alteracao] = $this->registos();
        $alteracao->refresh();

        $this->assertSame(ModoAlteracaoPreco::APLICAR_GERADAS, $alteracao->modo);
        $this->assertSame('Aplicar às propinas já geradas', $alteracao->modo_descricao);
        $this->assertSame($admin->id, $alteracao->criado_por);
        $this->assertSame('2027-01-01', $alteracao->mes_efeito->toDateString());
        $this->assertSame(3_000_000, $alteracao->valor_novo->unidadesMenores());
    }

    public function test_a_alteracao_nunca_muda_nem_se_elimina(): void
    {
        ['alteracao' => $alteracao] = $this->registos();

        try {
            $alteracao->update(['motivo' => 'Outro']);
            $this->fail('Uma alteração registada não pode mudar.');
        } catch (LogicException $e) {
            $this->assertStringContainsString('nunca muda', $e->getMessage());
        }

        $this->expectException(LogicException::class);
        $alteracao->delete();
    }

    public function test_o_ajuste_so_se_cria_e_a_propina_lista_os_seus_ajustes_por_ordem(): void
    {
        ['plano' => $plano, 'propina' => $propina, 'alteracao' => $alteracao] = $this->registos();
        $primeiro = PropinaAjuste::create([
            'alteracao_preco_id' => $alteracao->id,
            'propina_id' => $propina->id,
            'valor_anterior' => Dinheiro::deUnidadesMenores(2_500_000),
            'valor_novo' => Dinheiro::deUnidadesMenores(3_000_000),
            'valor_pago_no_momento' => Dinheiro::deUnidadesMenores(0),
            'plano_anterior_id' => $plano->id,
            'plano_novo_id' => null,
            'motivo' => 'Actualização anual',
        ]);

        $this->assertNull($primeiro->getAttribute('updated_at'));
        $this->assertNotNull($primeiro->created_at);
        $this->assertSame([$primeiro->id], $propina->ajustes()->pluck('id')->all());

        try {
            $primeiro->update(['motivo' => 'Outro']);
            $this->fail('Um ajuste não pode mudar.');
        } catch (LogicException $e) {
            $this->assertStringContainsString('só se acrescentam', $e->getMessage());
        }

        $this->expectException(LogicException::class);
        $primeiro->delete();
    }

    public function test_a_reconciliacao_exige_valor_igual_ao_original_mais_os_ajustes(): void
    {
        ['propina' => $propina] = $this->registos();
        // Fora da app (só no teste): um valor mudado sem ajuste tem de ser apanhado pela reconciliação.
        DB::table('propinas')->where('id', $propina->id)->update(['valor' => 3_000_000]);

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('valor = valor_original + Σ(ajustes)');

        $this->assertPropinasCoerentes();
    }

    public function test_a_reconciliacao_aceita_um_valor_explicado_pelos_ajustes_encadeados(): void
    {
        ['plano' => $plano, 'propina' => $propina] = $this->registos();
        DB::table('propinas')->where('id', $propina->id)->update(['valor' => 2_800_000]);

        foreach ([[2_500_000, 3_000_000], [3_000_000, 2_800_000]] as [$antes, $depois]) {
            PropinaAjuste::create([
                'alteracao_preco_id' => $this->novaAlteracao($plano)->id,
                'propina_id' => $propina->id,
                'valor_anterior' => Dinheiro::deUnidadesMenores($antes),
                'valor_novo' => Dinheiro::deUnidadesMenores($depois),
                'valor_pago_no_momento' => Dinheiro::deUnidadesMenores(0),
                'plano_anterior_id' => $plano->id,
                'motivo' => 'Teste',
            ]);
        }

        $this->assertPropinasCoerentes();
    }

    private function novaAlteracao(PlanoPropina $plano): AlteracaoPrecoPlano
    {
        return AlteracaoPrecoPlano::create([
            'ano_lectivo_id' => $plano->ano_lectivo_id,
            'plano_propina_id' => $plano->id,
            'modo' => ModoAlteracaoPreco::APLICAR_GERADAS,
            'valor_anterior' => Dinheiro::deUnidadesMenores(2_500_000),
            'valor_novo' => Dinheiro::deUnidadesMenores(2_800_000),
            'mes_efeito' => '2027-01-01',
            'motivo' => 'Teste',
            'total_propinas_afectadas' => 1,
            'impressao_digital' => str_repeat('b', 64),
        ]);
    }
}
