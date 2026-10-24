<?php

namespace Modules\Financeiro\Tests\Feature;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Modules\Financeiro\Actions\EliminarCambioAction;
use Modules\Financeiro\Actions\EliminarMetodoPagamentoAction;
use Modules\Financeiro\Actions\EliminarPlanoPropinaAction;
use Modules\Financeiro\Actions\EliminarProdutoAction;
use Modules\Financeiro\Actions\EliminarServicoAction;
use Modules\Financeiro\Enums\TipoMetodoPagamento;
use Modules\Financeiro\Models\Cambio;
use Modules\Financeiro\Models\MetodoPagamento;
use Modules\Financeiro\Models\Produto;
use Modules\Financeiro\Models\Servico;
use Modules\Financeiro\Support\Dinheiro;
use Modules\Financeiro\Support\TaxaCambio;
use Modules\Financeiro\Tests\Concerns\ComDadosAcademicosFinanceiro;
use Modules\Permissao\Database\Seeders\PermissaoDatabaseSeeder;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Segunda linha de defesa: a pré-verificação de ReferenciasFinanceiras não vê
 * tabelas futuras, mas a BD rejeita o delete. Usa-se uma FK real (tabela
 * dependente criada no teste) para provar que a Action devolve o erro amigável.
 */
class EliminarComChaveEstrangeiraTest extends TestCase
{
    use ComDadosAcademicosFinanceiro;
    use RefreshDatabase;

    private const MENSAGEM = 'Não é possível eliminar: existem registos que dependem deste.';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissaoDatabaseSeeder::class);
    }

    /** @return array<string, array{0: string}> */
    public static function recursos(): array
    {
        return [
            'produto' => ['produto'],
            'servico' => ['servico'],
            'metodo de pagamento' => ['metodo'],
            'cambio' => ['cambio'],
            'plano de propina' => ['plano'],
        ];
    }

    /** @return array{0: Model, 1: Closure} */
    private function preparar(string $recurso): array
    {
        return match ($recurso) {
            'produto' => [
                $m = Produto::create(['nome' => 'P', 'preco' => Dinheiro::deUnidadesMenores(100)]),
                fn () => app(EliminarProdutoAction::class)->executar($m),
            ],
            'servico' => [
                $m = Servico::create(['nome' => 'S', 'preco' => Dinheiro::deUnidadesMenores(100)]),
                fn () => app(EliminarServicoAction::class)->executar($m),
            ],
            'metodo' => [
                $m = MetodoPagamento::create(['nome' => 'Num', 'tipo' => TipoMetodoPagamento::NUMERARIO]),
                fn () => app(EliminarMetodoPagamentoAction::class)->executar($m),
            ],
            'cambio' => [
                $m = Cambio::create(['moeda_cotada' => 'AOA', 'moeda_base' => 'USD', 'data' => '2026-10-01', 'taxa' => TaxaCambio::deDecimal('900')->micros()]),
                fn () => app(EliminarCambioAction::class)->executar($m),
            ],
            'plano' => [
                $m = $this->plano($this->anoLectivo(), 'Plano FK'),
                fn () => app(EliminarPlanoPropinaAction::class)->executar($m),
            ],
        };
    }

    #[DataProvider('recursos')]
    public function test_fk_da_bd_vira_erro_amigavel_e_nada_e_eliminado(string $recurso): void
    {
        [$modelo, $eliminar] = $this->preparar($recurso);

        // Tabela dependente invisível a ReferenciasFinanceiras, com FK real.
        Schema::create('dependente_teste', function ($table) use ($modelo) {
            $table->id();
            $table->foreignId('ref_id')->constrained($modelo->getTable())->restrictOnDelete();
        });
        DB::table('dependente_teste')->insert(['ref_id' => $modelo->getKey()]);

        try {
            $eliminar();
            $this->fail('Devia lançar ValidationException.');
        } catch (ValidationException $e) {
            $this->assertSame(['eliminar' => [self::MENSAGEM]], $e->errors());
        }

        $this->assertDatabaseHas($modelo->getTable(), [$modelo->getKeyName() => $modelo->getKey()]);
    }

    #[DataProvider('recursos')]
    public function test_sem_dependentes_elimina_normalmente(string $recurso): void
    {
        [$modelo, $eliminar] = $this->preparar($recurso);

        $eliminar();

        $this->assertDatabaseMissing($modelo->getTable(), [$modelo->getKeyName() => $modelo->getKey()]);
    }
}
