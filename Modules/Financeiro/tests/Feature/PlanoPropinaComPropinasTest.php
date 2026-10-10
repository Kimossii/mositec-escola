<?php

namespace Modules\Financeiro\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Modules\Financeiro\Actions\AtualizarPlanoPropinaAction;
use Modules\Financeiro\DTO\PlanoPropinaDTO;
use Modules\Financeiro\Enums\EstadoCobranca;
use Modules\Financeiro\Enums\Periodicidade;
use Modules\Financeiro\Models\PlanoPropina;
use Modules\Financeiro\Models\Propina;
use Modules\Financeiro\Support\Dinheiro;
use Modules\Financeiro\Tests\Concerns\ComDadosAcademicosFinanceiro;
use Modules\Financeiro\Tests\Concerns\ComPropinasFinanceiro;
use Modules\Financeiro\Tests\Concerns\ComUtilizadoresFinanceiro;
use Modules\Permissao\Database\Seeders\PermissaoDatabaseSeeder;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PlanoPropinaComPropinasTest extends TestCase
{
    use ComDadosAcademicosFinanceiro;
    use ComPropinasFinanceiro;
    use ComUtilizadoresFinanceiro;
    use RefreshDatabase;

    private const BASE = 'financeiro.configuracao.planos-propina.';

    private const MSG_VALOR = 'Este plano já tem propinas geradas: o valor não pode ser alterado na edição do plano. As propinas existentes mantêm o seu valor.';

    private const MSG_CALENDARIO = 'Este plano já tem propinas geradas: a periodicidade e os meses de início e de fim não podem ser alterados, porque definem os períodos das propinas existentes.';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissaoDatabaseSeeder::class);
    }

    /** O payload que mantém tudo como está (plano geral Set → Jun, 25.000,00, mensal). */
    private function payload(PlanoPropina $plano, array $sobrepor = []): array
    {
        return array_merge([
            'nome' => $plano->nome,
            'descricao' => null,
            'periodicidade' => $plano->periodicidade->value,
            'valor' => '25000',
            'mes_inicio' => $plano->mes_inicio,
            'mes_fim' => $plano->mes_fim,
            'alvos' => [],
            'confirmar_plano_geral' => true,
        ], $sobrepor);
    }

    /** @return array{0: PlanoPropina, 1: Propina, 2: array<string, mixed>} */
    private function planoComPropina(): array
    {
        $cenario = $this->cenarioPropinas();

        return [$cenario['plano'], $this->propina($cenario['matricula'], $cenario['plano']), $cenario];
    }

    public function test_com_propinas_o_valor_nao_muda_e_nada_e_gravado(): void
    {
        [$plano, $propina] = $this->planoComPropina();

        $this->actingAs($this->adminEscola())->from('/x')
            ->put(route(self::BASE . 'update', $plano), $this->payload($plano, ['valor' => '30000', 'nome' => 'Renomeado']))
            ->assertSessionHasErrors(['valor' => self::MSG_VALOR]);

        $this->assertSame(2_500_000, $plano->fresh()->valor->unidadesMenores());
        $this->assertSame('Propina Mensal', $plano->fresh()->nome); // tudo ou nada
        $this->assertSame(2_500_000, $propina->fresh()->valor->unidadesMenores());
    }

    #[DataProvider('alteracoesDeCalendario')]
    public function test_com_propinas_o_calendario_nao_muda(array $sobrepor): void
    {
        [$plano] = $this->planoComPropina();

        $this->actingAs($this->adminEscola())->from('/x')
            ->put(route(self::BASE . 'update', $plano), $this->payload($plano, $sobrepor))
            ->assertSessionHasErrors(['periodicidade' => self::MSG_CALENDARIO]);

        $plano->refresh();
        $this->assertSame(Periodicidade::MENSAL, $plano->periodicidade);
        $this->assertSame([1, 9, 6], [$plano->intervalo_meses, $plano->mes_inicio, $plano->mes_fim]);
    }

    public static function alteracoesDeCalendario(): array
    {
        return [
            'periodicidade' => [['periodicidade' => Periodicidade::TRIMESTRAL->value]],
            'mês de início' => [['mes_inicio' => 10]],
            'mês de fim' => [['mes_fim' => 7]],
            'outra periodicidade' => [['periodicidade' => Periodicidade::OUTRA->value, 'intervalo_meses' => 2]],
        ];
    }

    public function test_com_propinas_nome_descricao_e_alvos_continuam_editaveis_sem_tocar_nas_propinas(): void
    {
        [$plano, $propina, $cenario] = $this->planoComPropina();
        $antes = $propina->fresh()->getAttributes();

        $this->actingAs($this->adminEscola())->from('/x')
            ->put(route(self::BASE . 'update', $plano), $this->payload($plano, [
                'nome' => 'Propina Mensal (revista)',
                'descricao' => 'Só gerações futuras',
                'alvos' => [['nivel_academico_id' => $cenario['turma']->nivel_academico_id]],
            ]))
            ->assertSessionHasNoErrors();

        $this->assertSame('Propina Mensal (revista)', $plano->fresh()->nome);
        $this->assertSame(1, $plano->alvos()->count());
        $this->assertSame($antes, $propina->fresh()->getAttributes());
    }

    public function test_uma_propina_cancelada_basta_para_bloquear(): void
    {
        [$plano, $propina] = $this->planoComPropina();
        $this->comEstado($propina, EstadoCobranca::CANCELADA);

        $this->actingAs($this->adminEscola())->from('/x')
            ->put(route(self::BASE . 'update', $plano), $this->payload($plano, ['valor' => '30000']))
            ->assertSessionHasErrors(['valor' => self::MSG_VALOR]);
    }

    public function test_sem_propinas_valor_e_calendario_continuam_editaveis(): void
    {
        $plano = $this->plano($this->anoLectivo(), 'Sem Propinas');

        $this->actingAs($this->adminEscola())->from('/x')
            ->put(route(self::BASE . 'update', $plano), $this->payload($plano, ['valor' => '30000', 'periodicidade' => Periodicidade::TRIMESTRAL->value]))
            ->assertSessionHasNoErrors();

        $this->assertSame(3_000_000, $plano->fresh()->valor->unidadesMenores());
        $this->assertSame(3, $plano->fresh()->intervalo_meses);
    }

    public function test_a_action_recusa_mesmo_sem_passar_pelo_pedido_http(): void
    {
        [$plano] = $this->planoComPropina();
        $dto = new PlanoPropinaDTO(
            nome: $plano->nome,
            periodicidade: Periodicidade::MENSAL,
            intervalo_meses: 1,
            valor: Dinheiro::deUnidadesMenores(3_000_000),
            mes_inicio: 10,
            mes_fim: 6,
            alvos: [],
        );

        try {
            app(AtualizarPlanoPropinaAction::class)->executar($plano, $dto);
            $this->fail('A Action devia recusar.');
        } catch (ValidationException $e) {
            $this->assertSame(['valor' => [self::MSG_VALOR], 'periodicidade' => [self::MSG_CALENDARIO]], $e->errors());
        }

        $this->assertSame(2_500_000, $plano->fresh()->valor->unidadesMenores());
        $this->assertSame(9, $plano->fresh()->mes_inicio);
    }

    public function test_eliminar_um_plano_com_propinas_e_bloqueado(): void
    {
        [$plano] = $this->planoComPropina();

        $this->actingAs($this->adminEscola())->from('/x')
            ->delete(route(self::BASE . 'destroy', $plano))
            ->assertSessionHasErrors(['eliminar' => 'Não é possível eliminar este plano de propina: já existem registos financeiros que o utilizam. Desative-o.']);

        $this->assertNotNull(PlanoPropina::query()->find($plano->id));
    }
}
