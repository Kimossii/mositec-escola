<?php

namespace Modules\Financeiro\Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use LogicException;
use Modules\Financeiro\Enums\EstadoMulta;
use Modules\Financeiro\Enums\TipoMulta;
use Modules\Financeiro\Models\PropinaMulta;
use Modules\Financeiro\Tests\Concerns\ComDadosAcademicosFinanceiro;
use Modules\Financeiro\Tests\Concerns\ComMultasFinanceiro;
use Modules\Financeiro\Tests\Concerns\ComPropinasFinanceiro;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PropinaMultaModelTest extends TestCase
{
    use ComDadosAcademicosFinanceiro;
    use ComMultasFinanceiro;
    use ComPropinasFinanceiro;
    use RefreshDatabase;

    private function umaMulta(array $atributos = []): PropinaMulta
    {
        ['plano' => $plano, 'matricula' => $matricula] = $this->cenarioPropinas();

        return $this->multa($this->propina($matricula, $plano), 25_000, $atributos);
    }

    public function test_a_tabela_tem_a_unicidade_1_para_1(): void
    {
        $indices = collect(Schema::getIndexes('propina_multas'))->keyBy('name');

        $this->assertTrue($indices->has('propina_multas_propina_unique'));
        $this->assertTrue($indices['propina_multas_propina_unique']['unique']);
        $this->assertSame(['tenant_id', 'propina_id'], $indices['propina_multas_propina_unique']['columns']);
    }

    public function test_criar_sincroniza_descricao_e_por_omissao(): void
    {
        $multa = $this->umaMulta()->refresh();

        $this->assertSame(EstadoMulta::ACTIVA, $multa->estado);
        $this->assertSame('Activa', $multa->estado_descricao);
        $this->assertSame(0, $multa->valor_pago->unidadesMenores());
        $this->assertFalse($multa->bloqueada_automatico);
        $this->assertSame(25_000, $multa->pendente()->unidadesMenores());
        $this->assertSame(TipoMulta::PERCENTAGEM, $multa->tipo);
        $this->assertSame(500, $multa->valor_regra);
        $this->assertSame($this->tenant->id, (int) $multa->tenant_id);
    }

    public function test_uma_propina_so_tem_uma_multa(): void
    {
        ['plano' => $plano, 'matricula' => $matricula] = $this->cenarioPropinas();
        $propina = $this->propina($matricula, $plano);
        $this->multa($propina, 25_000);

        $this->expectException(QueryException::class);

        $this->multa($propina, 30_000);
    }

    public function test_a_lista_de_imutaveis(): void
    {
        $this->assertSame(['propina_id', 'base_capital'], PropinaMulta::IMUTAVEIS);
    }

    #[DataProvider('imutaveis')]
    public function test_propina_e_base_de_capital_nunca_mudam(string $campo, mixed $novo): void
    {
        $multa = $this->umaMulta();

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('A propina e a base de capital de uma multa nunca mudam: ' . $campo . '.');

        $multa->forceFill([$campo => $novo])->save();
    }

    public static function imutaveis(): array
    {
        return [
            'propina' => ['propina_id', 999],
            'base de capital' => ['base_capital', 1],
        ];
    }

    public function test_uma_multa_nunca_e_eliminada(): void
    {
        $multa = $this->umaMulta();

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Uma multa nunca é eliminada.');

        $multa->delete();
    }

    #[DataProvider('criacoesInvalidas')]
    public function test_invariantes_de_criacao(array $atributos, string $mensagem): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage($mensagem);

        $this->umaMulta($atributos);
    }

    public static function criacoesInvalidas(): array
    {
        return [
            'base de capital zero' => [['base_capital' => 0], 'A base de capital de uma multa tem de ser maior que zero.'],
            'escalao zero' => [['escalao_ordem' => 0], 'O escalão de uma multa tem de ser maior ou igual a 1.'],
            'sem tipo' => [['tipo' => null], 'Uma multa tem de guardar o tipo e o valor do escalão aplicado.'],
            'valor da regra zero' => [['valor_regra' => 0], 'Uma multa tem de guardar o tipo e o valor do escalão aplicado.'],
        ];
    }

    public function test_valor_zero_e_valido_com_estado_isenta(): void
    {
        ['plano' => $plano, 'matricula' => $matricula] = $this->cenarioPropinas();
        $multa = $this->multa($this->propina($matricula, $plano), 0);
        $multa->forceFill(['estado' => EstadoMulta::ISENTA])->save();

        $multa->refresh();
        $this->assertSame(0, $multa->valor->unidadesMenores());
        $this->assertSame(0, $multa->valor_pago->unidadesMenores());
        $this->assertSame(EstadoMulta::ISENTA, $multa->estado);
        $this->assertSame('Isenta', $multa->estado_descricao);
        $this->assertSame(0, $multa->pendente()->unidadesMenores());
    }

    public function test_valor_zero_com_valor_pago_acima_continua_a_falhar(): void
    {
        ['plano' => $plano, 'matricula' => $matricula] = $this->cenarioPropinas();
        $multa = $this->multa($this->propina($matricula, $plano), 0);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('O valor pago da multa não pode exceder o valor da multa.');

        $multa->forceFill(['valor_pago' => 1])->save();
    }

    public function test_valor_pago_nao_pode_exceder_o_valor(): void
    {
        $multa = $this->umaMulta();

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('O valor pago da multa não pode exceder o valor da multa.');

        $multa->forceFill(['valor_pago' => 25_001])->save();
    }

    public function test_relacoes_e_multapendente(): void
    {
        ['plano' => $plano, 'matricula' => $matricula] = $this->cenarioPropinas();
        $propina = $this->propina($matricula, $plano);
        $multa = $this->multa($propina, 25_000);

        $this->assertSame($propina->id, $multa->propina->id);
        $this->assertSame($multa->id, $propina->multa->id);
        $this->assertSame(25_000, $propina->multaPendente());
    }

    public function test_multapendente_e_zero_sem_multa_ou_com_multa_anulada(): void
    {
        ['plano' => $plano, 'matricula' => $matricula] = $this->cenarioPropinas();
        $propina = $this->propina($matricula, $plano);
        $this->assertSame(0, $propina->multaPendente());

        $multa = $this->multa($propina, 25_000);
        $multa->forceFill(['estado' => EstadoMulta::ANULADA])->save();

        $this->assertSame(0, $propina->fresh()->multaPendente());
    }
}
